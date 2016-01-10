<?php
namespace App\Jobs;

use DB;
use Log;
use Quartz;
use p3k\Multipart;
use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use DateTime, DateTimeZone;

class TripComplete extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;
  private $_data;

  public function __construct($dbid, $data) {
    $this->_dbid = $dbid;
    $this->_data = $data;
  }

  public function handle() {
    // echo "Job Data\n";
    // echo json_encode($this->_data)."\n";
    if(!is_array($this->_data)) return;

    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();

    Log::info("Starting job for ".$db->name);

    if(!$db->micropub_endpoint) {
      Log::info('No micropub endpoint configured for database ' . $db->name);
      return;
    }

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');

    // Load the data from the start and end times
    $start = new DateTime($this->_data['properties']['start']);
    $end = new DateTime($this->_data['properties']['end']);
    $results = $qz->queryRange($start, $end);
    $features = [];
    foreach($results as $id=>$record) {
      if(!property_exists($record->data->properties, 'action')) {
        $record->data->properties = array_filter((array)$record->data->properties, function($k){
          // Remove some of the app-specific tracking keys
          return !in_array($k, ['locations_in_payload','desired_accuracy','significant_change','pauses','deferred']);
        }, ARRAY_FILTER_USE_KEY);
        $features[] = $record->data;
      }
    }

    // Build the GeoJSON for this trip
    $geojson = [
      'type' => 'FeatureCollection',
      'features' => $features
    ];
    $file_path = tempnam(sys_get_temp_dir(), 'compass');
    file_put_contents($file_path, json_encode($geojson));

    // Reverse geocode the start and end location to get an h-adr
    $startAdr = [
      'type' => 'h-adr',
      'properties' => [
        'latitude' => $this->_data['properties']['start-coordinates'][1],
        'longitude' => $this->_data['properties']['start-coordinates'][0],
      ]
    ];
    $endAdr = [
      'type' => 'h-adr',
      'properties' => [
        'latitude' => $this->_data['properties']['end-coordinates'][1],
        'longitude' => $this->_data['properties']['end-coordinates'][0],
      ]
    ];
    Log::info('Looking up start and end locations');
    $start = self::geocode($this->_data['properties']['start-coordinates'][1], $this->_data['properties']['start-coordinates'][0]);
    if($start) {
      $startAdr['properties']['locality'] = $start->locality;
      $startAdr['properties']['region'] = $start->region;
      $startAdr['properties']['country'] = $start->country;
      Log::info('Found start: '.$start->full_name.' '.$start->timezone);
    }
    $end = self::geocode($this->_data['properties']['end-coordinates'][1], $this->_data['properties']['end-coordinates'][0]);
    if($end) {
      $endAdr['properties']['locality'] = $end->locality;
      $endAdr['properties']['region'] = $end->region;
      $endAdr['properties']['country'] = $end->country;
      Log::info('Found end: '.$end->full_name.' '.$end->timezone);
    }

    // Set the timezone of the dates based on the location
    $startDate = new DateTime($this->_data['properties']['start']);
    if($start && $start->timezone) {
      $startDate->setTimeZone(new DateTimeZone($start->timezone));
    }
    $endDate = new DateTime($this->_data['properties']['end']);
    if($end && $end->timezone) {
      $endDate->setTimeZone(new DateTimeZone($end->timezone));
    }

    $params = [
      'h' => 'entry',
      'created' => $endDate->format('c'),
      'trip' => [
        'type' => 'h-trip',
        'properties' => [
          'mode-of-transport' => $this->_data['properties']['mode'],
          'start' => $startDate->format('c'),
          'end' => $endDate->format('c'),
          'start-location' => $startAdr,
          'end-location' => $endAdr,
          'distance' => [
            'type' => 'h-measure',
            'properties' => [
              'num' => round($this->_data['properties']['distance']),
              'unit' => 'meter'
            ]
          ],
          'duration' => [
            'type' => 'h-measure',
            'properties' => [
              'num' => round($this->_data['properties']['duration']),
              'unit' => 'second'
            ]
          ],
          'route' => 'route.json'
          // TODO: avgpace
          // TODO: avgspeed
        ]
      ]
    ];

    // echo "Micropub Params\n";
    // print_r($params);

    $multipart = new Multipart();
    $multipart->addArray($params);
    $multipart->addFile('route.json', $file_path, 'application/json');

    $httpheaders = [
      'Authorization: Bearer ' . $db->micropub_token,
      'Content-type: ' . $multipart->contentType()
    ];

    Log::info('Sending to the Micropub endpoint: '.$db->micropub_endpoint);
    // Post to the Micropub endpoint
    $ch = curl_init($db->micropub_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart->data());
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);

    Log::info("Done!");
    Log::info($response);

    // echo "========\n";
    // echo $response."\n========\n";
    //
    // echo "\n";
  }

  public static function geocode($lat, $lng) {
    $ch = curl_init(env('ATLAS_BASE').'api/geocode?latitude='.$lat.'&longitude='.$lng);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    if($response) {
      return json_decode($response);
    }
  }

}