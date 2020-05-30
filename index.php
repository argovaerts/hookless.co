<?php
require 'vendor/autoload.php';

session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

use SimpleCrud\Database;
use SimpleCrud\Scheme\Cache;
use SimpleCrud\Scheme\Sqlite;

use Hookless\Constants;

// SQLite database
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

$users  = $db->users;
$jobs   = $db->jobs;

// dispatcher
$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
    // sign on flow
    $r->addRoute('GET', '/', 'get_landing');
    $r->addRoute('GET', '/app', 'get_app');
    $r->addRoute('POST', '/app', 'post_send_code');

    // API
    $r->addRoute('GET', '/user', 'get_user');
    $r->addRoute('POST', '/user', 'post_user');
    $r->addRoute('GET', '/job', 'get_scheduled_jobs');
    $r->addRoute('POST', '/job', 'post_new_job');
    $r->addRoute('GET', '/job/{id}', 'get_job_details');
});

// Fetch method and URI from somewhere
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Strip query string (?foo=bar) and decode URI
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        http_response_code(405);
        break;
    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];

        switch($handler) {

            // sign in flow
            case 'get_landing':
                include 'pages/landing.html';
            break;
            case 'get_app':
                // Log request
                file_put_contents('logs/sign_in.log', '[ ' . date('c') . ' ] ' . json_encode([
                    'session'   => $_SESSION,
                    'get'       => $_GET,
                    'post'      => $_POST
                ]) . PHP_EOL, FILE_APPEND);

                // If we have a user, we need to check it
                if(isset($_SESSION['code'])) {
                    $user = $users->get(['email' => $_SESSION['email_to_be_checked']]);
                    $created = new DateTime($user->code_created);
                    $created = $created->add(new DateInterval('PT5M'));

                    $now = new DateTime();

                    if($user->code === $_SESSION['code'] && $created > (new DateTime())) {
                        // auth ok
                        file_put_contents('logs/sign_in.log', '[ ' . date('c') . ' ] ' . $user->email . ' signed in successfully' . PHP_EOL, FILE_APPEND);
                        
                        // if user has api key use that, otherwise generate one
                        if(is_null($user->api_key)) {
                            $user->api_key = implode('-', str_split(substr(strtolower(md5(microtime().rand(1000, 9999))), 0, 30), 6));
                            $user->save();
                        }

                        $page = file_get_contents('pages/app.html');
                        $page = str_replace('{{api_key}}', $user->api_key, $page);
                        echo $page;
                    }
                    else {
                        // auth failed
                        unset($_SESSION['code']);
                        unset($_SESSION['sign_in_send']);
                        unset($_SESSION['email_to_be_checked']);
                        header('Location: /');
                    }
                }
                // If we send the mail, we need to ask for the code
                elseif(isset($_SESSION['sign_in_send']) && $_SESSION['sign_in_send']) {
                    $page = file_get_contents('pages/app_sign_in_second_step.html');
                    $page = str_replace('{{email}}', $_SESSION['email_to_be_checked'], $page);
                    echo $page;
                }
                else {
                    header('Location: /');
                }
            break;
            case 'post_send_code':
                // Log request
                file_put_contents('logs/sign_in.log', '[ ' . date('c') . ' ] ' . json_encode([
                    'session'   => $_SESSION,
                    'get'       => $_GET,
                    'post'      => $_POST
                ]) . PHP_EOL, FILE_APPEND);

                // If we have the code and an email, we need to make a session of it
                if(isset($_POST['code']) && isset($_POST['email'])) {
                    $_SESSION['code'] = $_POST['code'];
                    $_SESSION['email_to_be_checked'] = $_POST['email'];
                }
                // If we have an email, we need to send the code
                elseif(isset($_POST['email'])) {
                    $uniq = uniqid();

                    $user = $users->getOrCreate(['email' => $_POST['email']]);
                    $user->code = $uniq;
                    $user->code_created = date('c');
                    $user->save();

                    $mail = new PHPMailer(false);
                    $mail->isSMTP();                                            
                    $mail->Host         = Constants::MAIL_HOST;
                    $mail->SMTPAuth     = true;
                    $mail->Username     = Constants::MAIL_USER;
                    $mail->Password     = Constants::MAIL_PASSWORD;
                    $mail->SMTPSecure   = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port         = Constants::MAIL_PORT;

                    //$mail->setFrom('hello@hookless.co', 'Hookless.co');
                    $mail->setFrom(Constants::MAIL_FROM[0], Constants::MAIL_FROM[1]);
                    $mail->addAddress($_POST['email']);
                    $mail->Subject      = 'Your login code';
                    $mail->Body         = 'Your code is: ' . $uniq;
                    $mail->send();

                    $_SESSION['sign_in_send'] = true;
                    $_SESSION['email_to_be_checked'] = $_POST['email'];
                }
    
                header('Location: /app', 303);
            break;

            // API
            case 'get_user':
                header('Content-Type: application/json');
                if(isset($_SERVER['HTTP_X_KEY'])) {
                    $user = $users->get(['api_key' => $_SERVER['HTTP_X_KEY']]);
                    if(!is_null($user)) {
                        $array = json_decode(json_encode($user), true);
                        unset($array['id']);
                        unset($array['code']);
                        unset($array['code_created']);
                        $array['requests_made'] = (int)$array['requests_made'];
                        $array['validated'] = (int)$array['validated'];
                        echo json_encode($array);
                    }
                    else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not a valid API key']);
                    }
                }
                else {
                    http_response_code(401);
                    echo json_encode(['error' => 'No API key set']);
                }
            break;
            case 'post_user':
                header('Content-Type: application/json');
                if(isset($_SERVER['HTTP_X_KEY'])) {
                    $user = $users->get(['api_key' => $_SERVER['HTTP_X_KEY']]);
                    if(!is_null($user)) {
                        $input = json_decode(file_get_contents('php://input'), true);
                        foreach($input as $key=>$value) {
                            switch($key) {
                                case 'email':
                                    $user->email = $value;
                                break;
                                case 'first_name':
                                    $user->first_name = $value;
                                break;
                                case 'last_name':
                                    $user->last_name = $value;
                                break;
                                case 'company':
                                    $user->company = $value;
                                break;
                                case 'vat_number':
                                    $user->vat_number = $value;
                                break;
                                case 'address':
                                    $user->address = $value;
                                break;
                                case 'postal_code':
                                    $user->postal_code = $value;
                                break;
                                case 'town':
                                    $user->town = $value;
                                break;
                                case 'country':
                                    $user->country = $value;
                                break;
                            }
                        }
                        $user->save();

                        http_response_code(201);
                        $array = json_decode(json_encode($user), true);
                        unset($array['id']);
                        unset($array['code']);
                        unset($array['code_created']);
                        $array['requests_made'] = (int)$array['requests_made'];
                        $array['validated'] = (int)$array['validated'];
                        echo json_encode($array);
                    }
                    else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not a valid API key']);
                    }
                }
                else {
                    http_response_code(401);
                    echo json_encode(['error' => 'No API key set']);
                }
            break;
            case 'get_scheduled_jobs':
                header('Content-Type: application/json');
                if(isset($_SERVER['HTTP_X_KEY'])) {
                    $user = $users->get(['api_key' => $_SERVER['HTTP_X_KEY']]);
                    if(!is_null($user)) {
                        $query = $jobs->select()->where('user_id = ', $user->id)->get();
                        $resp = [];
                        foreach($query as $result) {
                            $resp[] = [
                                'id'    => $result->id,
                                'url'   => 'https://' . $_SERVER['HTTP_HOST'] . '/job/' . $result->id
                            ];
                        }
                        echo json_encode($resp);
                    }
                    else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not a valid API key']);
                    }
                }
                else {
                    http_response_code(401);
                    echo json_encode(['error' => 'No API key set']);
                }
            break;
            case 'post_new_job':
                header('Content-Type: application/json');
                if(isset($_SERVER['HTTP_X_KEY'])) {
                    $user = $users->get(['api_key' => $_SERVER['HTTP_X_KEY']]);
                    if(!is_null($user)) {
                        $input = json_decode(file_get_contents('php://input'), true);

                        if(!isset($input['webhook'])) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Not all required data was present.']);
                            exit();
                        }

                        if(isset($input['run_every']) && isset($input['run_at'])) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Too many variables were present.']);
                            exit();
                        }
                        elseif(isset($input['run_every'])) {
                            $input['run_at'] = null;
                            $input['is_recurring'] = true;
                        }
                        elseif(isset($input['run_at'])) {
                            $input['run_every'] = null;
                            $input['is_recurring'] = false;
                        }
                        else {
                            http_response_code(400);
                            echo json_encode(['error' => 'Not all required data was present.']);
                            exit();
                        }

                        if(!isset($input['payload'])) {
                            $input['payload'] = null;
                        }
                        if(!isset($input['request_type'])) {
                            $input['request_type'] = 'POST';
                        }
                        if(!isset($input['is_multipart'])) {
                            $input['is_multipart'] = false;
                        }

                        http_response_code(201);
                        $job = $jobs->create([
                            'user_id'       => $user->id,
                            'is_recurring'  => $input['is_recurring'],
                            'run_every'     => $input['run_every'],
                            'run_at'        => $input['run_at'],
                            'webhook'       => $input['webhook'],
                            'request_type'  => $input['request_type']
                        ]);
                        $job->save();
                        echo json_encode($job);
                    }
                    else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not a valid API key']);
                    }
                }
                else {
                    http_response_code(401);
                    echo json_encode(['error' => 'No API key set']);
                }
            break;
            case 'get_job_details':
                header('Content-Type: application/json');
                if(isset($_SERVER['HTTP_X_KEY'])) {
                    $user = $users->get(['api_key' => $_SERVER['HTTP_X_KEY']]);
                    if(!is_null($user)) {
                        $job = $jobs[$vars['id']];
                        if($job->user_id === $user->id) {
                            $resp = [
                                'id'            => $job->id,
                                'request_type'  => $job->request_type,
                                'webhook'       => $job->webhook,
                                'payload'       => $job->payload,
                            ];

                            if((int)$job->is_recurring) {
                                $resp['run_every'] = $job->run_every;
                            }
                            else {
                                $resp['run_at'] = $job->run_at;
                            }

                            echo json_encode($resp);
                        }
                        else {
                            http_response_code(401);
                            echo json_encode(['error' => 'Not a valid API key']);
                        }
                    }
                    else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not a valid API key']);
                    }
                }
                else {
                    http_response_code(401);
                    echo json_encode(['error' => 'No API key set']);
                }
            break;
        }
        break;
}