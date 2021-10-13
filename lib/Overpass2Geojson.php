<?php

class Overpass2Geojson {
	
    /**
     * Converts a JSON string or decoded array into a GeoJSON string or array.
     * This creates a feature for each supplied node, way, or relation. Ways
     * that are part of relations and nodes that are part of ways are not being
	 * created as separate features.
     * @param  mixed   $input  JSON string or array
     * @param  boolean $encode whether to encode output as string
     * @return mixed           false if failed, otherwise GeoJSON string or array
     */
	 public static function convertElements($input, $encode = true) {
	
		$inputArray = self::validateInput($input);
        if (!$inputArray) {
            return false;
        }
		
        $nodes = self::collectNodes($inputArray['elements']);
		
		$output = array(
            'type' => 'FeatureCollection',
            'features' => array(),
        );
		
		// arrays to collect relation ways and way nodes
		$relationWayIds = array();
		$wayNodeIds = array();
		
		// multipolygons
		foreach ($inputArray['elements'] as $osmItem) {
			if (isset($osmItem['type']) && $osmItem['type'] === 'relation' && $osmItem['tags']['type'] == 'multipolygon') {
				$ways = array();
				foreach ($osmItem['members'] as $member) {
					$memberId = $member['ref'];
					$wayNodes = $inputArray['elements'][array_search($member['ref'], array_column($inputArray['elements'], 'nodes'))];
					foreach($wayNodes as $wayNode) {
						$wayNodeIds[] = $wayNode;
					}
					$feature = self::createMemberFeature($inputArray['elements'][array_search($member['ref'], array_column($inputArray['elements'], 'id'))], $nodes);
					
					// check for ways that are part of a relation
					if ($feature) {
						$ways[] = $feature;
					}
					$relationWayIds[] = $member['ref'];
				}
				$output['features'][] = self::createMultipolygonFeature($osmItem, $ways);
            }
		}
		
		// ways
		foreach ($inputArray['elements'] as $osmItem) {
            if (isset($osmItem['type']) && $osmItem['type'] === 'way') {
				if(!in_array($osmItem['id'],$relationWayIds)) {
					
					$feature = self::createWayFeature($osmItem, $nodes);
					if ($feature) {
						$output['features'][] = $feature;
					}
				}
				
				// check for nodes that are part of a way
				foreach($osmItem['nodes'] as $wayNode) {
					$wayNodeIds[] = $wayNode;
				}
            }
		}
		
		// nodes
		foreach ($nodes as $node) {
			if (isset($node['tags']) && $node['tags'] && !in_array($node['id'],$wayNodeIds)) {
				$output['features'][] = array(
					'type' => 'Feature',
					'properties' => $node['tags'],
					'geometry' => array(
						'type' => 'Point',
						'coordinates' => array($node['lon'], $node['lat']),
					),
				);
			}
        }
		
        return $encode ? json_encode($output, JSON_PRETTY_PRINT) : $output;
	}
		
    /**
     * Converts a JSON string or decoded array into a GeoJSON string or array.
     * This creates a LineString feature for each supplied way, using the nodes
     * only as points for the LineString.
     * @param  mixed   $input  JSON string or array
     * @param  boolean $encode whether to encode output as strin
     * @return mixed           false if failed, otherwise GeoJSON string or array
     */
    public static function convertWays($input, $encode = true) {
	    
        $inputArray = self::validateInput($input);
        if (!$inputArray) {
            return false;
        }
        $nodes = self::collectNodes($inputArray['elements']);
        $output = array(
            'type' => 'FeatureCollection',
            'features' => array(),
        );
        foreach ($inputArray['elements'] as $osmItem) {
            if (isset($osmItem['type']) && $osmItem['type'] === 'way') {
                $feature = self::createWayFeature($osmItem, $nodes);
                if ($feature) {
                    $output['features'][] = $feature;
                }
            }
        }
        return $encode ? json_encode($output, JSON_PRETTY_PRINT) : $output;
    }
	
    /**
     * Converts a JSON string or decoded array into a GeoJSON string or array.
     * This creates a Polygon feature for each supplied relation with member ways
     * and their respective nodes as points
     * @param  mixed   $input  JSON string or array
     * @param  boolean $encode whether to encode output as string
     * @return mixed           false if failed, otherwise GeoJSON string or array
     */
    public static function convertRelations($input, $encode = true) {
	    
        $inputArray = self::validateInput($input);
        if (!$inputArray) {
            return false;
        }
		$output = array(
            'type' => 'FeatureCollection',
            'features' => array(),
        );
		$nodes = self::collectNodes($inputArray['elements']);

        foreach ($inputArray['elements'] as $osmItem) {
            if (isset($osmItem['type']) && $osmItem['type'] === 'relation') {
				$ways = array();
				foreach ($osmItem['members'] as $member) {
					$feature = self::createMemberFeature($inputArray['elements'][array_search($member['ref'], array_column($inputArray['elements'], 'id'))], $nodes);
					if ($feature) {
						$ways[] = $feature;
					}
				}
				$output['features'][] = self::createMultipolygonFeature($osmItem, $ways);
            }
        }
        return $encode ? json_encode($output, JSON_PRETTY_PRINT) : $output;
    }

    /**
     * Converts a JSON string or decoded array into a GeoJSON string or array.
     * This creates a Point feature for each supplied node, ignoring ways.
     * @param  mixed   $input  JSON string or array
     * @param  boolean $encode whether to encode output as string
     * @return mixed           false if failed, otherwise GeoJSON string or array
     */
    public static function convertNodes($input, $encode = true) {
        $inputArray = self::validateInput($input);
        $nodes = self::collectNodes($inputArray['elements']);
        $output = array(
            'type' => 'FeatureCollection',
            'features' => array(),
        );
        foreach ($nodes as $node) {
            $output['features'][] = array(
                'type' => 'Feature',
                'properties' => isset($node['tags']) ? $node['tags'] : array(),
                'geometry' => array(
                    'type' => 'Point',
                    'coordinates' => array($node['lon'], $node['lat']),
                ),
            );
        }
        return $encode ? json_encode($output, JSON_PRETTY_PRINT) : $output;
    }

    private static function validateInput($input) {
        if (is_array($input)) {
            $inputArray = $input;
        } else if (is_string($input)) {
            $inputArray = json_decode($input, true);
        } else {
            return false;
        }
        if (!is_array($inputArray) ||
            !isset($inputArray['elements']) ||
            !is_array($inputArray['elements'])) {

            return false;
        }
        return $inputArray;
    }

    /**
     * Creates an array of nodes indexed by node id
     * @param  array $elements  OSM items
     * @return array            nodes e.g. [id => {lon, lat, tags}, ...]
     */
    public static function collectNodes($elements) {
        $nodes = array();
        if (!is_array($elements)) {
            return $nodes;
        }
        foreach ($elements as $osmItem) {
            if (isset($osmItem['type']) && $osmItem['type'] === 'node') {
                if (isset($osmItem['id']) && isset($osmItem['lat']) && isset($osmItem['lon'])) {
                    $nodes[$osmItem['id']] = $osmItem;
                }
            }
        }
        return $nodes;
    }

    /**
     * Creates a Feature array with geometry from matching nodes
     * @param  array $way  OSM way
     * @param  array $nodes    OSM node coordinates indexed by id
     * @return mixed           false if invalid feature otherwise
     *                         array GeoJSON Feature with LineString or Polygon geometry
     */
    public static function createWayFeature($way, $nodes) {
        $coords = array();
        if (isset($way['nodes'])) {
            foreach ($way['nodes'] as $nodeId) {
                if (isset($nodes[$nodeId])) {
                    $coords[] = array($nodes[$nodeId]['lon'], $nodes[$nodeId]['lat']);
                }
            }
        }

		// polygon detection: compare coordinates of first and last node of way
		$is_polygon = false;
		if($coords[0] == $coords[sizeof($coords)-1]) $is_polygon = true;
		
        if (count($coords) >= 2) {
            return array(
                'type' => 'Feature',
                'geometry' => array(
                    'type' => $is_polygon ? 'Polygon' : 'LineString',
                    'coordinates' => $is_polygon ? [$coords] : $coords,
                ),
                'properties' => isset($way['tags']) ? array_merge($way['tags'], ["id"=>$way['id']]) : ["id"=>$way['id']],
            );
        }
        return false;
    }
	
    /**
     * Creates a Feature array with geometry from matching ways
     * @param  array $relation OSM relation
     * @param  array $ways	   OSM ways as members of relation
     * @return mixed           false if invalid feature otherwise
     *                         array GeoJSON Feature with Polygon geometry
     */
    public static function createMultipolygonFeature($relation, $ways) {
		return array(
			'type' => 'Feature',
			'geometry' => array(
				'type' => 'Polygon',
				'coordinates' => $ways,
			),
			'properties' => isset($relation['tags']) ? array_merge($relation['tags'], ["id"=>$relation['id']]) : ["id"=>$relation['id']],
		);
	}
	
	/**
     * Creates a Relation member Feature array with geometry from matching nodes
     * @param  array $way  OSM way
     * @param  array $nodes    OSM node coordinates indexed by id
     * @return mixed           false if invalid feature otherwise
     *                         array with coordinate pairs of relation member
     */
    public static function createMemberFeature($way, $nodes) {
        $coords = array();
        if (isset($way['nodes'])) {
            foreach ($way['nodes'] as $nodeId) {
                if (isset($nodes[$nodeId])) {
                    $coords[] = array($nodes[$nodeId]['lon'], $nodes[$nodeId]['lat']);
                }
            }
        }
        if (count($coords) >= 2) {
            return $coords;
		}
        return false;
    }
}