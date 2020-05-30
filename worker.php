<?php

require 'vendor/autoload.php';

use GO\Scheduler;

use SimpleCrud\Database;
use SimpleCrud\Scheme\Cache;
use SimpleCrud\Scheme\Sqlite;

use GuzzleHttp\Client;

$pdo = new PDO('sqlite:pages/db.db');
$db = new Database($pdo);
/*if ($cache->has('db_scheme')) {
    $array = $cache->get('db_scheme');
    $scheme = new Cache($array);
}
else {
    $scheme = new Sqlite($pdo);
    $cache->save('db_scheme', $scheme->toArray());
}
$db = new Database($pdo, $scheme);*/

$scheduler = new Scheduler([
    'tempDir' => 'temp'
]);

$users = $db->users;
$jobs = $db->jobs->select()->get();
foreach($jobs as $job) {
    $user = $users[$job->user_id];
    if($user->validated) {
        $run = $scheduler->call(function ($request_type, $url, $payload, $is_multipart) {
            $client = new Client();
            $options = [
                'headers'   => [
                    'User-Agent'    => 'Hookless/1.0'
                ]
            ];
            if(!is_null($payload)) {
                if($is_multipart) {
                    $options['multipart'] = json_decode($payload, true);
                }
                else {
                    $options['json'] = json_decode($payload, true);
                }
            }
            $client->request($request_type, $url, $options);
            return true;
        }, [
            $job->request_type,
            $job->webhook,
            $job->payload,
            $job->is_multipart
        ]);

        if($job->is_recurring) {
            switch ($job->run_every) {
                case 'minute':
                    $run = $run->everyMinute();
                break;
                case '5 minutes':
                    $run = $run->everyMinute(5);
                break;
                case '10 minutes':
                    $run = $run->everyMinute(10);
                break;
                case '15 minutes':
                    $run = $run->everyMinute(15);
                break;
                case '30 minutes':
                    $run = $run->everyMinute(30);
                break;
                case '60 minutes':
                case 'hour':
                    $run = $run->everyMinute(60);
                break;
                case '120 minutes':
                case '2 hours':
                    $run = $run->everyMinute(120);
                break;
                case 'day':
                    $run = $run->daily();
                break;
                case 'monday':
                    $run = $run->monday();
                break;
                case 'tuesday':
                    $run = $run->tuesday();
                break;
                case 'wednesday':
                    $run = $run->wednesday();
                break;
                case 'thursday':
                    $run = $run->thursday();
                break;
                case 'friday':
                    $run = $run->friday();
                break;
                case 'saturday':
                    $run = $run->saturday();
                break;
            }
        }
        else {
            $run = $run->date($job->run_at);
        }

        $run->then(function() use ($job, $users) {
            $user = $users[$job->user_id];
            $user->requests_made = ((int)$user->requests_made) + 1;
            $user->save();
        }, true)->onlyOne();
    }
}

$scheduler->run();