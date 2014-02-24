<?php

require_once 'config.php';

use \Guzzle\Http\Client;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;

$app->match('/', function (\Silex\Application $app, Request $request) {
    $pictures = $app['db_repo']->getPictures($request->query->get('limit', 30));

    return $app['twig']->render('index.html.twig', array(
        'pictures' => $pictures
    ));
})->bind('homepage');

// Handles Real-Time Updates Of Certain Hashtags from Instagram API
// To register to subscription, run curl request:
// curl -F 'client_id=CLIENT-ID' \
//        -F 'client_secret=CLIENT-SECRET' \
//        -F 'object=tag' \
//        -F 'aspect=media' \
//        -F 'object_id=hashtag' \
//        -F 'callback_url=http://YOUR-CALLBACK/URL' \
//        https://api.instagram.com/v1/subscriptions/
//    When you POST with the info above to create a new subscription,
//    we simultaneously submit a GET request to your callback URL
//    with the following parameters:
//
//    hub.mode - This will be set to "subscribe"
//    hub.challenge - This will be set to a random string that your callback URL will need to echo back in order to verify you'd like to subscribe.
//    hub.verify_token - This will be set to whatever verify_token passed in with the subscription request. It's helpful to use this to differentiate between multiple subscription requests.
//    EXAMPLE: http://your-callback.com/url/?hub.mode=subscribe&hub.challenge=15f7d1a91c1f40f8a748fd134752feb3&hub.verify_token=myVerifyToken

//    To see current subscriptions:
//    https://api.instagram.com/v1/subscriptions?client_secret=CLIENT-SECRET&client_id=CLIENT-ID

//    MORE INFO: http://instagram.com/developer/realtime/


$app->match('/realtime', function (\Silex\Application $app, Request $request) {
    // respond to subscription verification
    // Either PHP or Symfony Request Component replacing hub.mode to hub_mode, get accordingly.
    if ($request->isMethod('GET') && $request->query->get('hub_mode') == 'subscribe') {
        $app['monolog']->addInfo('Responding to challenge subscription check request');
        return $request->query->get('hub_challenge');
    }

    $signature = $request->headers->get('X-Hub-Signature');
    $postData = $request->getContent();

    if ($signature != ($calculated_signature = hash_hmac('sha1', $postData, INSTAGRAM_CLIENT_SECRET))) {
        // signature mismatch !!!
        $app['monolog']->addError('Signature Mismatch, Header: '.$signature.' != Calculated: '.$calculated_signature);
        $app->abort(400, 'Signature Mismatch, Header: '.$signature.' != Calculated: '.$calculated_signature);
    }

    // since we only have 2 seconds to respond, we pass handling of
    // realtime updates to finish method, which handles updates after the response.
    $app['realtime_finish'] = true;
    return $app->json(array(
        'status' => true
    ));
})->bind('realtime');

$app->finish(function (Request $request, Response $response) use ($app) {
    if (isset($app['realtime_finish']) && $app['realtime_finish'] == true) {
        // handle real-time updates

        $updates = json_decode($request->getContent(), true);
        if (json_last_error() === JSON_ERROR_NONE && $updates) {
            $activeTags = $app['db_repo']->getActiveTags();

            foreach ($updates as $update) {
                if ($update['object'] == 'tag' && in_array($update['object_id'], $activeTags, true)) {
                    instagram_check_tag_updates($app, $update['object_id']);
                    $app['monolog']->addInfo('Received update for '.$update['object'].' ('.$update['object_id'].'), server-time: '.date('Y-m-d H:i:s', $update['time']));
                }
            }
        } else {
            $app['monolog']->addError('Unable to parse realtime update JSON, Reason:' . json_last_error());
        }
    }
});

$app->match('/check', function (\Silex\Application $app, Request $request) {
    $activeTags = $app['db_repo']->getActiveTags();

    $tagResponses = array();
    foreach ($activeTags as $tag) {
        $tagResponses[$tag['tag']] = instagram_check_tag_updates($app, $tag['tag']);
    }

    $pictures = $app['db_repo']->getPictures($request->query->get('limit', 30));

    $html = $app['twig']->render('pictures.html.twig', array(
        'pictures' => $pictures
    ));

    return $app->json(array(
        'status' => true,
        'tag_responses' => $tagResponses,
        'html' => $html
    ));
})->bind('check');

$app->match('/print-image', function (\Silex\Application $app, Request $request) {
    $id = $request->query->get('id');

    $picture = $app['db_repo']->getPictureById($id);

    if (!$picture) {
        $app->abort(400, 'Picture id is wrong');
    }

    $imagine = new \Imagine\Gd\Imagine();

    $imageData = file_get_contents($picture['image_url']);

    if (!$imageData) {
        $app->abort(400, 'Failed to read image from: '. $picture['image_url']);
    }

    // x -> 50, y -> 138
    $image = $imagine->load(file_get_contents($picture['image_url']));
    $coverpath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR .
        'cover' . DIRECTORY_SEPARATOR . 'cover1.png';

    $cover = $imagine->open($coverpath);
    $coverFix = $imagine->open($coverpath);
    $cover->paste($image, new \Imagine\Image\Point(50, 138));
    $cover->paste($coverFix, new \Imagine\Image\Point(0, 0));

    $filepath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR .
        'photo' . DIRECTORY_SEPARATOR . $picture['picture_id'] . '.jpg';

    $cover->save($filepath);

//    $data = file_get_contents($filepath);
//    unlink($filepath);
//    file_put_contents($filepath, $data);

    return $app['twig']->render('print-image.html.twig', array(
        'picture' => $picture
    ));
})->bind('print-image');

$app->match('/print-done', function (\Silex\Application $app, Request $request) {
    $id = $request->query->get('id');

    if ($printDatetime = $app['db_repo']->updatePicturePrintStatus($id)) {
        return $app->json(array(
            'status' => true,
            'printed_at' => $printDatetime
        ));
    }

    return $app->json(array(
        'status' => false
    ));
})->bind('print-done');

function instagram_check_tag_updates(\Silex\Application $app, $tag, $minId = null, $maxId = null) {
    //    https://api.instagram.com/v1/tags/dushanbe/media/recent
    $client = new Client('https://api.instagram.com/v1/tags/{hashtag}/media', array(
        'hashtag' => $tag
    ));

    $request = $client->get('recent');
    $request->getQuery()->set('client_id', INSTAGRAM_CLIENT_ID);
    $request->getQuery()->set('count', 100);

    if ($minId) {
        $request->getQuery()->set('min_id', $minId);
    }
    if ($maxId) {
        $request->getQuery()->set('max_id', $maxId);
    }

    try {
        $response = $request->send()->json();
    } catch (\Guzzle\Http\Exception\BadResponseException $e) {
        $app['monolog']->addCritical(array(
            'Instagram Check Tag Updates Critical: ',
            'Uh oh! ' . $e->getMessage(),
            'HTTP request URL: ' . $e->getRequest()->getUrl(),
            'HTTP request: ' . $e->getRequest(),
            'HTTP response status: ' . $e->getResponse()->getStatusCode(),
            'HTTP response: ' . $e->getResponse(),
        ));

        return $app->json(array(
            'status' => false
        ));
    } catch (\Exception $e) {
        $app['monolog']->addCritical('Instagram Check Tag Updates Unknown Critical: '. $e->getMessage());

        return array(
            'status' => false
        );
    }

    /** @var \Doctrine\DBAL\Connection $db */
    $db = $app['db'];

    $pictures_received_count = 0;
    foreach ($response['data'] as $picture) {
        if ($picture['type'] == 'image') {
            $existing_picture = $db->fetchAssoc('SELECT id FROM pictures WHERE picture_id = ?', array($picture['id']));
            if (!$existing_picture) {
                $db->insert('pictures', array(
                    'picture_id' => $picture['id'],
                    'user_id' => $picture['user']['id'],
                    'username' => $picture['user']['username'],
                    'user_fullname' => $picture['user']['full_name'],
                    'user_profile_picture' => $picture['user']['profile_picture'],
                    'caption' => $picture['caption']['text'],
                    'image_url' => $picture['images']['standard_resolution']['url'],
                    'instagram_link' => $picture['link'],
                    'tag' => $tag,
                    'created_at' => date('Y-m-d H:i:s')
                ));
                $pictures_received_count++;
            }
        }
    }

    return array(
        'status' => true,
        'pictures_received_count' => $pictures_received_count
    );
}

$app->run();