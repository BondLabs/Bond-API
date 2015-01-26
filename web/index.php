<?php

// TODO: make a function to check if row exists for a given ID

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

if(getenv('DATABASE_URL')) {
    $app->register(new Herrera\Pdo\PdoServiceProvider(),
      array(
          'pdo.dsn' => 'pgsql:dbname='.ltrim($dbopts["path"],'/').';host='.$dbopts["host"],
          'pdo.port' => $dbopts["port"],
          'pdo.username' => $dbopts["user"],
          'pdo.password' => $dbopts["pass"]
      )
    );
} else {
    $app->register(new Herrera\Pdo\PdoServiceProvider(),
      array(
          'pdo.dsn' => 'pgsql:dbname=bond;host=localhost',
          'pdo.port' => 5432,
          'pdo.username' => "misbahkhan",
          'pdo.password' => ""
      )
    );
}

function doesexistID($id, $app) {
	$st = $app['pdo']->prepare('SELECT phone FROM users WHERE id=:id');
	$st->execute(array(':id' => $id));
	return $st->rowCount(); 
}

// Our web handlers

$app->get('/', function() use($app) {
    $app['monolog']->addDebug('logging output.');
    return 'Hello';
});

$app->get('/api/', function(Request $request) use($app) {
    return $app->json($request->headers->all(), 200); 
});

function generatekey($id, $app) {
	$rand = md5(uniqid($id, true));
	$st = $app['pdo']->prepare('SELECT auth_key FROM users WHERE auth_key=:key');
	$st->execute(array(':key' => $rand));
	if($st->rowCount() < 1){
		return $rand; 
	}
	return generatekey($id, $app); 
}

$app->get('/api/authall', function() use($app) {
	$st = $app['pdo']->prepare('SELECT id FROM users');
	$st->execute(); 
	$row = $st->fetchAll(); 

	for ($i = 0; $i < count($row); $i++){
		$user = $row[$i];
		$auth = generatekey($user['id'], $app);  
		echo $auth; 
		$st = $app['pdo']->prepare('UPDATE users SET auth_key=:key WHERE id=:id'); 
		$st->execute(array(':key' => $auth, ':id' => $user['id'])); 
	}

	return 'done'; 
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

function autherrors($id, $key, $app) {	
	if(!isauthkey($id, $key, $app)){
        return $app->json(array("error" => "Invalid authorization key."), 401);
    }

    if($id < 1 || empty($id)){
        return $app->json(array("error" => "Please provide a valid identification number."), 400);
    }
    
    if(empty($key)) {
        return $app->json(array("error" => "Authorization key is missing."), 403);     
	}
}

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
    $passeduid = $request->getRequestUri();
    $passeduid = explode("/", $passeduid);
    $id = $passeduid[3]; 

	return autherrors($id, $auth, $app);
};

$authPOST = function(Request $request) use($app) {
	$auth = $request->headers->get('x-auth-key'); 
	$id = $request->get('id');

	return autherrors($id, $auth, $app); 
};

$authBOND = function(Request $request) use($app) {
	$auth = $request->headers->get('x-auth-key'); 
	$id1 = $request->get('id1'); 
	$id2 = $request->get('id2');

    if($id1 < 1 || empty($id1) || $id2 < 1 || empty($id2)){
        return $app->json(array("error" => "Please provide a valid identification number."), 400);
    }
    
    if(empty($auth)) {
        return $app->json(array("error" => "Authorization key is missing."), 403);     
	}

	if(!isauthkey($id1, $auth, $app) && !isauthkey($id2, $auth, $app)){
        return $app->json(array("error" => "Invalid authorization key."), 401);
	}
};

$app->get('/api/bonds/{id}', function($id) use($app) {
	$st = $app['pdo']->prepare('SELECT bond_id FROM bonds WHERE id1=:id OR id2=:id'); 
	$st->execute(array(':id' => $id));
    $row = $st->fetchAll(PDO::FETCH_ASSOC);
	return $app->json($row, 200); 
})
-> before($auth);

$app->post('/api/bonds', function(Request $request) use($app) {
	$id1 = $request->get('id1'); 
	$id2 = $request->get('id2');

	if(empty($id1) || empty($id2)){
		return $app->json(array("error" => "Please provide valid identification numbers."), 400); 
	}

	if($id1 >= $id2){
		return $app->json(array("error" => "Identification number 1 must be less than identification number 2."), 400);
	}

	if(!doesexistID($id1, $app) || !doesexistID($id2, $app)){
		return $app->json(array("error" => "Please provide valid identification numbers."), 400); 
	}

	$st = $app['pdo']->prepare('SELECT bond_id FROM bonds WHERE id1=:id1 AND id2=:id2'); 
	$st->execute(array(':id1' => $id1, ':id2' => $id2)); 
	
	if($st->rowCount() > 0){
		return $app->json(array("error" => "A bond with the given information already exists."), 409);
	}

	$st = $app['pdo']->prepare('INSERT INTO bonds(id1, id2) VALUES(:id1, :id2)');
	$st->execute(array(':id1' => $id1, ':id2' => $id2));

	return $app->json(array("success" => "New bond created."), 200);
})
-> before($authBOND); 

$app->get('/api/locations/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('SELECT latitude, longitude FROM locations WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
   
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "No location was found for the given identification number."), 400); 
    }
})
-> before($auth); 

$app->delete('/api/locations/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM locations WHERE id=:id');
    $st->execute(array(':id' => $id));

    if($st->rowCount() > 0){
    	return $app->json(array("message" => "success"), 200); 
    }
    return $app->json(array("error" => "Please provide a valid identification number."), 400); 
})
-> before($auth); 

$app->post('/api/locations', function(Request $request) use($app) {
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$id = $request->get('id'); 
	$lat = $request->get('latitude'); 
	$lon = $request->get('longitude'); 

	if(!(v::float()->validate($lat) && v::float()->validate($lon))){
		return $app->json(array("error" => "Please provide a valid location."), 400); 		
	}

	$st = $app['pdo']->prepare("SELECT id FROM locations WHERE id=:id"); 
	$st->execute(array(':id' => $id)); 

	if($st->rowCount() > 0){
		// update old location
		$st = $app['pdo']->prepare("UPDATE locations SET latitude=:lat, longitude=:lon WHERE id=:id"); 
		$st->execute(array(':lat' => $lat, ':lon' => $lon, ':id' => $id)); 
		return $app->json(array("success" => "Location updated."), 200); 		
	} else {
		// insert new location
		$st = $app['pdo']->prepare("INSERT INTO locations(id, latitude, longitude) VALUES(:id, :lat, :lon)"); 
		$st->execute(array(':id' => $id, ':lat' => $lat, ':lon' => $lon)); 
		return $app->json(array("success" => "New location stored."), 200); 	
	}
})
->before($authPOST); 

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

$app->post('/api/images', function(Request $request) use($app) {
	$id = $request->get('id'); 
	$image = $request->get('image_data'); 
	$valid = v::string()->validate($image);
	
	if(empty($image) || !$valid) {
		return $app->json(array("error" => "Please provide an image."), 400); 
	}

	$st = $app['pdo']->prepare("SELECT id FROM images WHERE id=:id"); 
	$st->execute(array(':id' => $id));

	if($st->rowCount() > 0){
		// update old image
		$st = $app['pdo']->prepare("UPDATE images SET file=:image WHERE id=:id");
		$st->execute(array(':image' => $image));
		return $app->json(array("success" => "Image updated."), 200); 
	} else {
		// insert new image
		$st = $app['pdo']->prepare("INSERT INTO images(id, file) VALUES(:id, :image)");
		$st->execute(array(':id' => $id, ':image' => $image));
		return $app->json(array("success" => "New image stored."), 200); 
	}
}) 
-> before($authPOST); 

$app->delete('/api/images/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));

    if($st->rowCount() > 0) {    
		return $app->json(array("message" => "success"), 200); 
    } else {
        return $app->json(array("error" => "No image was found with the given identification number."), 412);
    }

    return $app->json(array("error" => "Something went wrong.  Please try again later."), 500);
})->before($auth); 

function doesexist($email, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE email=:email');
    $st->execute(array(':email' => $email));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    if($st->rowCount() > 0) {
        return true;
    }
    return false;
}

$app->delete('/api/users/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM users WHERE id=:id');
    $st->execute(array(':id' => $id));
    return $app->json(array("message" => "success"), 200); 
})->before($auth); 

$app->get('/api/users/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('SELECT * FROM users WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    unset($row["auth_key"]); 
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "Please provide a valid identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})->before($auth); 

$app->post('/api/login', function(Request $request) use ($app) {
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$api_key = $request->headers->get('x-api-key'); 

	if(empty($api_key)){
		return $app->json(array("error" => "API key is missing."), 403); 
	}

	if($api_key != "37D74DBC-C160-455D-B4AE-A6396FEE7954"){
		return $app->json(array("error" => "Invalid API key."), 401); 
	}
	
	$phone = $request->get('phone');
	$password = $request->get('password');

    $st = $app['pdo']->prepare('SELECT id, auth_key, password FROM users WHERE phone=:phone');
    $st->execute(array(':phone' => $phone));
    $row = $st->fetch(PDO::FETCH_ASSOC);
	
	if($st->rowCount() < 1) {
		return $app->json(array("error" => "Please provide a valid phone number."), 400); 
	}

	if(password_verify($password, $row['password'])){
		unset($row['password']); 
		return $app->json($row, 200); 
	} else {
		return $app->json(array("error" => "Please provide a valid password."), 400); 
	}

	return $app->json(array("error" => "Something went wrong.  Please try again later."), 500); 
});

// TODO: update existing user
$app->post('/api/users', function(Request $request) use($app) {
    $id = $request->get('id');
    $name = $request->get('name');
//    $email = $request->get('email');
    $phone = $request->get('phone');
    $password = $request->get('password');
    $age = $request->get('age');
    $gender = $request->get('gender');

    $valid = array();
    $valid["name"] = v::string()->length(1,32)->validate($name);
    $valid["password"] = v::string()->length(1,64)->validate($password);
//    $valid["email"] = v::email()->validate($email);
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
        // new user might need to be created 
        // check if email exists
        if(doesexist($email, $app)) {
            return $app->json(array("error" => "An account with the given information already exists."), 409); 
        }
        
        // email does not exist, continue to create user 

        // hash the password
        $password = password_hash($password, PASSWORD_DEFAULT);
       
		// generate auth key seed with phone number
		$newkey = generatekey($phone, $app);

        // insert into the database 
        $st = $app['pdo']->prepare("INSERT INTO users(name, phone, age, password, auth_key) VALUES(:name, :phone, :age, :password, :key) RETURNING id");
        $st->execute(array('name' => $name, 'phone' => $phone, 'age' => $age, 'password' => $password, 'key' => $newkey));
        
        $insertedrow = $st->fetchAll(); 

        // $insertedrow[0][{param}] seems to work, hopefully wont break ever.
        // return success json
        return $app->json(array("success" => "New user created", "id" => $insertedrow[0]["id"]), 200);
    } else {
        // update existing user
        return $app->json(array("log" => "update existing user"), 200);
    }  
});

$app->run();

?>
