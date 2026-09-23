<?php
/**
 * Optimization Engine — First Fit Decreasing (FFD) per bar size.
 *
 * Inventory is always consumed first, then the shortfall is bought on new
 * standard bars. Reusable off-cuts are returned so they can be auto-added
 * to inventory.
 *
 * @package VCO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCO_Optimizer {

	const SIZES = array( 8, 10, 12, 14, 16, 18, 20, 22, 25, 28, 32 );
	const EPS   = 0.0005; // 0.5mm tolerance.

	/** Unit weight kg/m = d^2 / 162 */
	public static function kg_per_meter( $size ) {
		return round( ( (float) $size * (float) $size ) / 162.0, 4 );
	}

	/**
	 * @param array $input {
	 *     stock_length, kerf_mm, min_reusable_m, scrap_ratio, kg_price,
	 *     cuts[], inventory[]
	 * }
	 * @return array
	 */
	public static function optimize( array $input ) {
		$stock   = isset( $input['stock_length'] ) ? floatval( $input['stock_length'] ) : 12;
		$kerf    = isset( $input['kerf_mm'] ) ? floatval( $input['kerf_mm'] ) / 1000.0 : 0.0;
		$minRem  = isset( $input['min_reusable_m'] ) ? floatval( $input['min_reusable_m'] ) : 0.5;
		$kgPrice = isset( $input['kg_price'] ) ? floatval( $input['kg_price'] ) : 0.0;
		$scrapR  = isset( $input['scrap_ratio'] ) ? floatval( $input['scrap_ratio'] ) : 0.3;
		if ( $stock <= 0 ) {
			$stock = 12;
		}
		if ( $kerf < 0 ) {
			$kerf = 0;
		}
		if ( $minRem < 0 ) {
			$minRem = 0;
		}

		// ---- 1. Group cut requirements by bar size (expand quantity) ----
		$groups = array();
		foreach ( ( isset( $input['cuts'] ) && is_array( $input['cuts'] ) ? $input['cuts'] : array() ) as $c ) {
			$size = intval( $c['size'] );
			$len  = floatval( $c['length'] );
			$qty  = intval( $c['quantity'] );
			if ( $size <= 0 || $len <= 0 || $qty <= 0 ) {
				continue;
			}
			$key = (string) $size;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'size' => $size, 'pieces' => array(), 'errors' => array() );
			}
			for ( $i = 0; $i < $qty; $i++ ) {
				$groups[ $key ]['pieces'][] = array(
					'length' => $len,
					'label'  => isset( $c['label'] ) ? sanitize_text_field( $c['label'] ) : '',
					'note'   => isset( $c['note'] ) ? sanitize_text_field( $c['note'] ) : '',
				);
			}
		}

		// ---- 2. Group inventory by bar size (expand quantity) ----
		$invGroups = array();
		foreach ( ( isset( $input['inventory'] ) && is_array( $input['inventory'] ) ? $input['inventory'] : array() ) as $r ) {
			$size = intval( $r['size'] );
			$len  = floatval( $r['bar_length'] );
			$qty  = intval( $r['quantity'] );
			if ( $size <= 0 || $len <= 0 || $qty <= 0 ) {
				continue;
			}
			$key = (string) $size;
			if ( ! isset( $invGroups[ $key ] ) ) {
				$invGroups[ $key ] = array();
			}
			for ( $i = 0; $i < $qty; $i++ ) {
				$invGroups[ $key ][] = array(
					'length'   => $len,
					'location' => isset( $r['location'] ) ? sanitize_text_field( $r['location'] ) : '',
				);
			}
		}

		// ---- 3. Solve each size group ----
		$resultGroups = array();
		$purchaseList = array();
		$offcuts      = array();
		$totals       = self::empty_totals();

		foreach ( $groups as $key => $g ) {
			$size = $g['size'];
			$kgm  = self::kg_per_meter( $size );

			$pieces = $g['pieces'];
			usort( $pieces, array( __CLASS__, 'cmp_desc_length' ) );

			$bars       = array();
			$invUsedLen = 0.0;
			$invUsedCnt = 0;
			$invBars    = array();

			// ---- 3a. Consume warehouse inventory first (FFD on real stock bars) ----
			if ( ! empty( $invGroups[ $key ] ) ) {
				$inv = $invGroups[ $key ];
				usort( $inv, array( __CLASS__, 'cmp_desc_barlength' ) );
				$remaining = $pieces;
				foreach ( $inv as $invBar ) {
					if ( empty( $remaining ) ) {
						break;
					}
					$bin   = self::open_bin( 'inventory', $invBar['length'], $invBar['location'] );
					$still = array();
					foreach ( $remaining as $p ) {
						$need = $p['length'] + $kerf;
						if ( $bin['used'] + $need <= $bin['length'] + self::EPS ) {
							$bin['used']  += $need;
							$bin['count'] += 1;
							$bin['pieces'][] = $p;
						} else {
							$still[] = $p;
						}
					}
					$remaining = $still;
					if ( ! empty( $bin['pieces'] ) ) {
						self::close_bin( $bin, $minRem );
						$bars[]      = $bin;
						$invUsedLen += $bin['length'];
						$invUsedCnt++;
						$invBars[]   = array(
							'bar_length' => $bin['length'],
							'location'   => $bin['location'],
							'cuts'       => $bin['count'],
						);
					}
				}
				$rest = $remaining;
			} else {
				$rest = $pieces;
			}

			// ---- 3b. Remaining pieces: FFD on new standard bars ----
			$newBins = array();
			foreach ( $rest as $p ) {
				$need = $p['length'] + $kerf;
				if ( $need > $stock + self::EPS ) {
					$g['errors'][] = sprintf(
						/* translators: 1: piece length, 2: stock length */
						'قطعه به طول %1$s متر بلندتر از شاخه استاندارد (%2$s متر) است و قابل برش نیست.',
						self::fmt( $p['length'] ),
						self::fmt( $stock )
					);
					continue;
				}
				$placed = false;
				foreach ( $newBins as $bi => $b ) {
					if ( $b['used'] + $need <= $b['length'] + self::EPS ) {
						$newBins[ $bi ]['used']  += $need;
						$newBins[ $bi ]['count'] += 1;
						$newBins[ $bi ]['pieces'][] = $p;
						$placed = true;
						break;
					}
				}
				if ( ! $placed ) {
					$nb           = self::open_bin( 'new', $stock, '' );
					$nb['used']   = $need;
					$nb['count']  = 1;
					$nb['pieces'] = array( $p );
					$newBins[]    = $nb;
				}
			}
			foreach ( $newBins as $b ) {
				self::close_bin( $b, $minRem );
				$bars[] = $b;
			}
			$barsNew = count( $newBins );

			// Return every physical remnant to inventory.
			foreach ( $bars as $b ) {
				$remnant = $b['reusable'] >= self::EPS ? $b['reusable'] : $b['waste'];
				if ( $remnant >= self::EPS ) {
					$offcuts[] = array(
						'size'   => $size,
						'length' => round( $remnant, 3 ),
						'source' => 'new' === $b['type'] ? 'ته‌مانده شاخه نو' : 'ته‌مانده انبار',
					);
				}
			}

			// ---- 3c. Statistics ----
			$usefulLen   = 0.0;
			$placedCount = 0;
			foreach ( $bars as $b ) {
				$placedCount += count( $b['pieces'] );
				foreach ( $b['pieces'] as $p ) {
					$usefulLen += $p['length'];
				}
			}
			$wasteLen = 0.0;
			$reusLen  = 0.0;
			$kerfLen  = 0.0;
			foreach ( $bars as $b ) {
				$wasteLen += $b['waste'];
				$reusLen  += $b['reusable'];
				$kerfLen  += $b['count'] * $kerf;
			}
			$supplyLen  = $barsNew * $stock + $invUsedLen;
			$totalWaste = $wasteLen + $kerfLen;
			$wastePct   = $supplyLen > 0 ? ( $totalWaste / $supplyLen ) * 100.0 : 0.0;

			$weightUseful   = $usefulLen * $kgm;
			$weightWaste    = $totalWaste * $kgm;
			$weightSupply   = $supplyLen * $kgm;
			$weightPurchase = $barsNew * $stock * $kgm;

			// Price is entered per kilogram (Rial).
			$cost       = $weightPurchase * $kgPrice;
			$scrapValue = $weightWaste * $kgPrice * $scrapR;
			$naiveCost  = ( $placedCount * $stock * $kgm ) * $kgPrice;
			$saving     = $naiveCost - $cost;

			$groupStat = array(
				'size'           => $size,
				'kg_per_meter'   => $kgm,
				'pieces_count'   => $placedCount,
				'useful_length'  => round( $usefulLen, 3 ),
				'new_bars'       => $barsNew,
				'inventory_bars' => $invUsedCnt,
				'inventory_len'  => round( $invUsedLen, 3 ),
				'supply_length'  => round( $supplyLen, 3 ),
				'waste_length'   => round( $totalWaste, 3 ),
				'leftover_waste' => round( $wasteLen, 3 ),
				'kerf_loss'      => round( $kerfLen, 3 ),
				'reusable_left'  => round( $reusLen, 3 ),
				'waste_percent'  => round( $wastePct, 2 ),
				'weight_useful'  => round( $weightUseful, 1 ),
				'weight_waste'   => round( $weightWaste, 1 ),
				'weight_supply'  => round( $weightSupply, 1 ),
				'weight_purchase'=> round( $weightPurchase, 1 ),
				'kg_price'       => $kgPrice,
				'cost'           => round( $cost, 0 ),
				'scrap_value'    => round( $scrapValue, 0 ),
				'naive_cost'     => round( $naiveCost, 0 ),
				'saving'         => round( $saving, 0 ),
				'errors'         => array_values( array_unique( $g['errors'] ) ),
			);

			$resultGroups[] = array( 'stat' => $groupStat, 'bars' => $bars, 'inventory_use' => $invBars );

			$totals['pieces_count']    += $groupStat['pieces_count'];
			$totals['useful_length']   += $groupStat['useful_length'];
			$totals['new_bars']        += $groupStat['new_bars'];
			$totals['inventory_bars']  += $groupStat['inventory_bars'];
			$totals['inventory_len']   += $groupStat['inventory_len'];
			$totals['supply_length']   += $groupStat['supply_length'];
			$totals['waste_length']    += $groupStat['waste_length'];
			$totals['reusable_left']   += $groupStat['reusable_left'];
			$totals['weight_useful']   += $groupStat['weight_useful'];
			$totals['weight_waste']    += $groupStat['weight_waste'];
			$totals['weight_purchase'] += $groupStat['weight_purchase'];
			$totals['cost']            += $groupStat['cost'];
			$totals['scrap_value']     += $groupStat['scrap_value'];
			$totals['naive_cost']      += $groupStat['naive_cost'];
			$totals['saving']          += $groupStat['saving'];

			if ( $barsNew > 0 ) {
				$purchaseList[] = array(
					'size'       => $size,
					'bar_length' => $stock,
					'qty_bars'   => $barsNew,
					'weight_kg'  => round( $weightPurchase, 1 ),
					'cost'       => round( $cost, 0 ),
				);
			}
		}

		usort( $resultGroups, array( __CLASS__, 'cmp_group' ) );
		usort( $purchaseList, array( __CLASS__, 'cmp_group' ) );

		$totals['waste_percent'] = $totals['supply_length'] > 0 ? round( ( $totals['waste_length'] / $totals['supply_length'] ) * 100, 2 ) : 0;

		return array(
			'config'        => array(
				'stock_length'   => $stock,
				'kerf_mm'        => round( $kerf * 1000, 2 ),
				'min_reusable_m' => $minRem,
				'scrap_ratio'    => $scrapR,
				'kg_price'       => $kgPrice,
			),
			'generated_at'  => gmdate( 'c' ),
			'groups'        => $resultGroups,
			'purchase_list' => $purchaseList,
			'offcuts'       => $offcuts,
			'totals'        => $totals,
		);
	}

	// ------------------------------------------------------------------

	private static function empty_totals() {
		return array(
			'pieces_count'   => 0,
			'useful_length'  => 0.0,
			'new_bars'       => 0,
			'inventory_bars' => 0,
			'inventory_len'  => 0.0,
			'supply_length'  => 0.0,
			'waste_length'   => 0.0,
			'reusable_left'  => 0.0,
			'weight_useful'  => 0.0,
			'weight_waste'   => 0.0,
			'weight_purchase'=> 0.0,
			'cost'           => 0.0,
			'scrap_value'    => 0.0,
			'naive_cost'     => 0.0,
			'saving'         => 0.0,
		);
	}

	private static function open_bin( $type, $length, $location ) {
		return array(
			'type'     => $type, // 'new' | 'inventory'
			'length'   => (float) $length,
			'location' => $location,
			'used'     => 0.0,
			'count'    => 0,
			'pieces'   => array(),
			'waste'    => 0.0,
			'reusable' => 0.0,
		);
	}

	private static function close_bin( &$bin, $minRem ) {
		$left = $bin['length'] - $bin['used'];
		if ( $left < self::EPS ) {
			$left = 0.0;
		}
		if ( $left >= $minRem - self::EPS ) {
			$bin['reusable'] = $left;
			$bin['waste']    = 0.0;
		} else {
			$bin['reusable'] = 0.0;
			$bin['waste']    = $left;
		}
		$bin['used']   = round( $bin['used'], 3 );
		$bin['length'] = round( $bin['length'], 3 );
	}

	public static function cmp_desc_length( $a, $b ) {
		return $b['length'] <=> $a['length'];
	}

	public static function cmp_desc_barlength( $a, $b ) {
		return $b['length'] <=> $a['length'];
	}

	private static function cmp_group( $a, $b ) {
		$sa = isset( $a['size'] ) ? intval( $a['size'] ) : intval( $a['stat']['size'] );
		$sb = isset( $b['size'] ) ? intval( $b['size'] ) : intval( $b['stat']['size'] );
		return $sa <=> $sb;
	}

	private static function fmt( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' );
	}
}
