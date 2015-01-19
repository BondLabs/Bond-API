<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;

// Validation class
use Respect\Validation\Validator as v;

$app = new Silex\Application();
$app['debug'] = true;


// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$dbopts = parse_url(getenv('DATABASE_URL'));
$app->register(new Herrera\Pdo\PdoServiceProvider(),
  array(
      'pdo.dsn' => 'pgsql:dbname='.ltrim($dbopts["path"],'/').';host='.$dbopts["host"],
      'pdo.port' => $dbopts["port"],
      'pdo.username' => $dbopts["user"],
      'pdo.password' => $dbopts["pass"]
  )
);


// Our web handlers

$app->get('/', function() use($app) {
    $app['monolog']->addDebug('logging output.');
    return 'Hello';
});

$app->get('/api/', function(Request $request) use($app) {
    return $app->json($request->headers->all(), 200); 
});

$app->post('/api/users', function() use($app) {
        
}); 

$app->delete('/api/users', function() use($app) {
    
}); 

function isauthkey($id, $key, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE auth_key=:key');
    $st->execute(array(':key' => $key));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    $uid = $res['id']; 

    if(intval($id) === intval($uid)){
        return true; 
    }
    return false;
}

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
    $passeduid = $request->getRequestUri();
    $passeduid = explode("/", $passeduid);
    $id = $passeduid[3]; 

    if(!isauthkey($id, $auth, $app)){
        return $app->json(array("error" => "Invalid authorization key."), 401);
    }

    if( $id < 1 || empty($id) ){
        return $app->json(array("error" => "Please provide a valid identification number."), 400);
    }
    
    if(empty($auth)) {
        return $app->json(array("error" => "Authorization key is missing."), 403);     
    }
};

$app->get('/api/images/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('SELECT file FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "No image was found for the given identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})
-> before($auth); 

function doesexist($email, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE email=:email');
    $st->execute(array(':email' => $email));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    if($st->rowCount() > 0) {
        return true;
    }
    return false;
}

// test if email exists or not
$app->get('/api/exist/{email}', function($email) use($app) {
    if(doesexist($email, $app)){
        return "email exists";     
    }
    return "email does not exist";
});

$app->post('/api/posttest', function() use($app) {
    return $post['stuff']; 
});

$app->post('/api/users', function() use($app) {
    $id = $post['id'];
    $name = $post['name'];
    $email = $post['email'];
    $phone = $post['password'];
    $age = $post['age'];
    $gender = $post['gender'];

    $valid = array();
    $valid["name"] = v::string()->length(1,32)->validate($name);
    $valid["email"] = v::email()->validate($email);
    $valid["phone"] = v::phone()->validate($phone); 
    $valid["age"] = v::numeric()->validate($age);

    $error = "";

    foreach($valid as $key=>$value) {
        if(!$valid[$key]) {
            $error.=$key." ";
        }
    }
    
    if(strlen($error) > 0) {
        return $app->json(array("error" => "Please provide a valid ".$error), 400);
    }

    if(empty($id)) {
        // create new user
        // first check if email exists
        if(doesexist($email, $app)) {
            return $app->json(array("error" => "An account with the given information already exists."), 409); 
        }
        
        // email does not exist, continue to create user 
        
    } else {
        // update existing user
    }  
}); 

$app->run();

?>
