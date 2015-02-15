<?php

// TODO: make a function to check if row exists for a given ID

require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;

// Validation class
use Respect\Validation\Validator as v;

$app = new Silex\Application();
	
$app['debug'] = true;

use Parse\ParsePush;
use Parse\ParseClient;

ParseClient::initialize( "8wBDcHUWQuhjX3eUksIJMQDCpgvfeFzJcX548TIp", 
						 "xAF1tTOKw7N2u7otV61IqpQT93oEVdzmtFX2gH1Y", 
						 "G1nzj3xvLrATTnePWF457vXivR2SXpswb7qvyYiT" );


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

function doesexistPHONE($phone, $app) {
	$st = $app['pdo']->prepare('SELECT id FROM users WHERE phone=:phone');
	$st->execute(array(':phone' => $phone));
	return $st->rowCount();
}

function doesexistBOND($bid, $app) {
	$st = $app['pdo']->prepare('SELECT id1 FROM bonds WHERE bond_id=:bid');
	$st->execute(array(':bid' => $bid));
	if($st->rowCount()) return true; 
	return false; 
}

function doesexistBONDUSERS($uid, $bid, $app) {
	if(!doesexistBOND($bid, $app)) return false;
	
	$st = $app['pdo']->prepare('SELECT id1, id2 FROM bonds WHERE bond_id=:id');
	$st->execute(array(':id' => $bid));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	if(intval($uid) === intval($row['id1']) || intval($uid) === intval($row['id2'])){
		return true; 	
	}	
	return false; 
}

function namesforotherusersinbonds($bid, $uid, $app) {
	if(empty($bid) || empty($uid)){
		return false; 		
	}
	$ids = array(); 
	$names = array(); 
	
	$st = $app['pdo']->prepare('SELECT id1, id2 FROM bonds WHERE bond_id=:bid');
	
	foreach($bid as $bondid){
		$st->execute(array(':bid' => $bondid));	
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$ids[$bondid] = intval($row['id1']) === intval($uid) ? $row['id2'] : $row['id1'];
	}

	$st = $app['pdo']->prepare("SELECT name FROM users WHERE id=:id");
	
	foreach($ids as $key => $name){
		$st->execute(array(':id' => $name));				
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$names[$key] = $row['name'];
	}
	return $names;
}

function nameforuid($uid, $app) {
	$st = $app['pdo']->prepare("SELECT name FROM users WHERE id=:id");
	$st->execute(array(':id' => $uid));
	return $st->fetch(PDO::FETCH_ASSOC)['name'];
}

function chatpushtouser($uid, $name, $bid, $message) {
	ParsePush::send(array(
		"channels" => [ "u".$uid  ],
		data => array(
			"alert" => $name." has sent you a new message.",
			"title" => "New Message!",
			"bid" => $bid,
			"name" => $name,
			"msg" => $message,
			"type" => "chat"
		)
	));
}

function bondpushtouser($uid, $name, $othername, $bid) {
	ParsePush::send(array(
		"channels" => [ "u".$uid  ],
		data => array(
			"alert" => "Hi ".$name.", you should meet ".$othername.". Open for details.",
			"title" => "New Bond!",
			"bid" => $bid,
			"type" => "bond"
		)
	));
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
	$rand = md5(uniqid($id.time(), true));
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

function isauthkeyforbond($bid, $key, $app) {
	$st = $app['pdo']->prepare('SELECT id1, id2 FROM bonds WHERE bond_id=:bid'); 
	$st->execute(array(':bid' => $bid)); 
	$row = $st->fetch(PDO::FETCH_ASSOC);	
	$id1 = $row['id1']; 
	$id2 = $row['id2'];
	
	if(isauthkey($id1, $key, $app) || isauthkey($id2, $key, $app)){
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

function matchingalgorithm($id1, $id2, $app) {
	$st = $app['pdo']->prepare("SELECT id, traits FROM traits WHERE id=:id1 OR id=:id2");	
	$st->execute(array(':id1' => $id1, ':id2' => $id2));
	$traits = $st->fetchAll(PDO::FETCH_ASSOC);
	
	$traits1 = $traits[0]['traits'];
	$traits2 = $traits[1]['traits'];

	$total = str_split($traits1&$traits2); 
	$count = 0;
	
	foreach($total as $bit){
		++$count;
	}

	$st = $app['pdo']->prepare("SELECT age FROM users WHERE id=:id");
	$st->execute(array(':id' => $id1));
	$age1 = $st->fetch(PDO::FETCH_ASSOC)['age'];
	
	$st->execute(array(':id' => $id2));
	$age2 = $st->fetch(PDO::FETCH_ASSOC)['age'];
	
	$diff = abs(intval($age1) - intval($age2));
	$diff/=4;

	if($diff+$count > 1) {
		return true;
	}
	return false; 
}

function createbond($id1, $id2, $app) {
	if(empty($id1) || empty($id2)){
		return $app->json(array("error" => "Please provide valid identification numbers."), 400); 
	}

	if($id1 > $id2){
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

	$st = $app['pdo']->prepare('INSERT INTO bonds(id1, id2) VALUES(:id1, :id2) RETURNING bond_id');
	$st->execute(array(':id1' => $id1, ':id2' => $id2));
    $ins = $st->fetchAll(); 

	bondpushtouser($id1, nameforuid($id1, $app), nameforuid($id2, $app), $ins[0]['bond_id']);	
	bondpushtouser($id2, nameforuid($id2, $app), nameforuid($id1, $app), $ins[0]['bond_id']);

	return $app->json(array("success" => "New bond created."), 200);
}

$app->get('/api/test/{id1}/{id2}', function($id1, $id2) use($app) {
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$st = $app['pdo']->prepare("SELECT id, traits FROM traits WHERE id=:id1 OR id=:id2"); 
	$st->execute(array(':id1' => $id1, ':id2' => $id2));
	$row = $st->fetchAll(PDO::FETCH_ASSOC);
	return matchingalgorithm($id1, $id2, $app);
}); 

// This middleware is to check for universal things with the auth_key
// To see if it exists or not, and things like that 
$authPRE = function(Request $request) use($app) {
	$auth = $request->headers->get('x-auth-key');
	if(empty($auth)) return $app->json(array("error" => "Authorization key is missing."), 403);
}; 

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
    $passeduid = $request->getRequestUri();
    $passeduid = explode("/", $passeduid);
    $id = $passeduid[3]; 

	return autherrors($id, $auth, $app);
};

$authAny = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
	$st = $app['pdo']->prepare("SELECT id FROM users WHERE auth_key=:auth_key");
	$st->execute(array('auth_key' => $auth));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return autherrors($row["id"], $auth, $app);
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

$authMESSAGE = function(Request $request) use($app) {
	$auth = $request->headers->get('x-auth-key'); 
	$id = $request->get('user_id');
	return autherrors($id, $auth, $app); 
}; 

$authBONDID = function(Request $request) use ($app) {
	$auth = $request->headers->get('x-auth-key');
	$bid = $request->get('bond_id');
	
	if(empty($auth)){
		return $app->json(array("error" => "Authorization key is missing"), 403);	
	}

	if(!doesexistBOND($bid, $app)){
		return $app->json(array("error" => "Please provide a valid identification number."), 400);
	}

	if(!isauthkeyforbond($bid, $auth, $app)){
		return $app->json(array("error" => "Invalid authorization key."), 401);
	}
};

$adminauth = function(Request $request) use($app) {
	$key = $request->get('key');
	$st = $app['pdo']->prepare("SELECT * FROM admins WHERE key=:key");
	$st->execute(array(':key' => $key));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	if(strcmp($row['key'], $key) !== 0){
		return $app->json(array('error' => 'invalid api key'), 400);
	}
};

$app->get('/analytics/{key}', function(Request $request) use($app) {
	$st = $app['pdo']->prepare("SELECT id FROM users");
	$st->execute();
	$usercount = $st->rowCount();

	$st = $app['pdo']->prepare("SELECT bond_id FROM bonds");
	$st->execute();
	$bondcount = $st->rowCount();

	$userbondratio = round($usercount/$bondcount, 2); 

	return $app->json(array('usercount' => $usercount, 'bondcount' => $bondcount, 'userbondratio' => $userbondratio), 200);
})
->before($adminauth);

$app->get('/api/{phone}', function($phone) use($app) {
	$value = doesexistPHONE($phone, $app);	
	return $app->json(array("doesexist" => $value), 200);
});

$app->post('/api/list', function(Request $request) use($app) {
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$id = $request->get('id');
	$list = $request->get('list');

	$st = $app['pdo']->prepare("SELECT list FROM links WHERE id=:id");
	$st->execute(array(':id' => $id));
	if($st->rowCount() > 0) {
		//update
		$st = $app['pdo']->prepare("UPDATE links SET list=:list WHERE id=:id");
		$st->execute(array(':list' => $list, ':id' => $id));
	} else {
		//insert
		$st = $app['pdo']->prepare("INSERT INTO links(id, list) VALUES(:id, :list)");
		$st->execute(array(':id' => $id, ':list' => $list));
	}

	// check for bond between every one
	// bond every one 
	
	$all = explode(",", $list);
	$st = $app['pdo']->prepare("SELECT list FROM links WHERE id=:id");
	$scraped = array(); 
	foreach($list as $idi){
		$st->execute(array(':id' => $idi));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$inrow = explode(",", $row);
		$scraped = array_merge($scraped, $inrow);
	}

	foreach($scraped as $idi){
		$id1 = $id > $idi ? $id : $idi; 
		$id2 = $id > $idi ? $id : $idi; 
		if(matchingalgorithm($id1, $id2, $app)){
			createbond($id1, $id2, $app);	
		}
	}

	return $app->json(array("message" => "Success."));	
})
->before($authPOST);

$app->get('/image/{id}', function($id) use($app) {
	$st = $app['pdo']->prepare("SELECT file from images WHERE id=:id");
	$st->execute(array(':id' => $id));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return $row['file'];
}); 

$app->post('/api/match', function(Request $request) use($app) {
	$id1 = $request->get('id1');
	$id2 = $request->get('id2');

	return matchingalgorithm($id1, $id2, $app);
})
->before($authBOND);

$app->get('/api/traits/{id}', function($id) use($app) {
	$st = $app['pdo']->prepare("SELECT traits FROM traits WHERE id=:id");
	$st->execute(array(':id' => $id));
	if($st->rowCount() > 0){
		$row = $st->fetch(PDO::FETCH_ASSOC);	
		return $app->json(array("traits" => $row['traits']), 200);
	} else {
		return $app->json(array("error" => "No traits were found for the given identification number."), 400);
	}
	return $app->json(array("error" => "Something went wrong.  Please try again later."), 500);	
})
->before($auth);

$app->post('/api/traits', function(Request $request) use($app) {
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$id = $request->get('id');
	$traits = $request->get('traits');

	$st = $app['pdo']->prepare("SELECT traits FROM traits WHERE id=:id");
	$st->execute(array(':id' => $id));

	if($st->rowCount() > 0){
		$st1 = $app['pdo']->prepare("UPDATE traits SET traits=:traits WHERE id=:id");
		$st2->execute(array(':id' => $id, ':traits' => $traits));
	} else {
		$st2 = $app['pdo']->prepare("INSERT INTO traits(id, traits) VALUES(:id, :traits)");
		$st2->execute(array(':id' => $id, ':traits' => $traits));
	}

	return $app->json(array("message" => "Success."), 200);
})
->before($authPOST);

$app->delete('/api/traits', function(Request $request) use($app) {
	$id = $request->get('id');
	$st = $app['pdo']->prepare("DELETE FROM traits WHERE id=:id");	
	$st->execute(array(':id' => $id));
	if($st->rowCount() > 0) {
		return $app->json(array("message" => "Success."), 200);
	} else {
		return $app->json(array("error" => "No traits were found for the given identification number."), 412);  
	}

	return $app->json(array("error" => "Something went wrong.  Please try again later."), 500);
})
->before($authPOST);

$app->get('/api/chats/{bond_id}', function($bond_id) use($app) {
	if(!doesexistBOND($bond_id, $app)){
		return $app->json(array("error", "Please provide a valid identification number."), 400); 	
	}

	$st = $app['pdo']->prepare("SELECT id1, id2 FROM bonds WHERE bond_id=:bid");
	$st->execute(array(':bid' => $bond_id));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	
	$id1 = $row['id1'];
	$id2 = $row['id2'];

	$st = $app['pdo']->prepare("SELECT id, messages, time FROM chats WHERE bond_id=:bid");
	$st->execute(array(':bid' => $bond_id));
	$row = $st->fetchAll(PDO::FETCH_ASSOC);

	if($st->rowCount() < 1){
		return $app->json(array("error" => "No chat was found for the given identification number."), 400); 
	} else {
		return $app->json(array("id1" => $id1, "id2" => $id2, "messages" => $row), 200);
	}
	return $app->json(array("error" => "Something went wrong.  Please try again later."), 500);	
})
->before($authBONDID); 

$app->delete('/api/chats', function(Request $request) use($app) {
	$bond_id = $request->get('bond_id');
	$st = $app['pdo']->prepare("DELETE FROM chats WHERE bond_id=:bid");
	$st->execute(array(':bid' => $bond_id));
	
	if($st->rowCount()){
		return $app->json(array("message" => "Success."), 200); 
	} else {
		return $app->json(array("error" => "No chat was found with the given identification number."), 412);
	}
	
	$app->json(array("error" => "Something went wrong.  Please try again later."), 500); 
})
->before($authBONDID);

$app->post('/api/chats', function(Request $request) use($app) {
	$bond_id = $request->get('bond_id');
	$user_id = $request->get('user_id');
	$message = $request->get('message');
	
	if(empty($message)){
		return $app->json(array("message" => "Please provide a valid message."), 400);
	}

	if(doesexistBONDUSERS($user_id, $bond_id, $app)) {
		$st = $app['pdo']->prepare("INSERT INTO chats (bond_id, id, messages) VALUES(:bid, :id, :msg)"); 
		$st->execute(array(':bid' => $bond_id, ':id' => $user_id, ':msg' => $message));	
		
		$st2 = $app['pdo']->prepare("SELECT id1, id2 FROM bonds WHERE bond_id=:bid");
		$st2->execute(array(':bid' => $bond_id));
		$row = $st2->fetch(PDO::FETCH_ASSOC);
			
		$otherid = (intval($user_id) === intval($row['id1']))?$row['id2']:$row['id1'];

		if($st->rowCount()){
			chatpushtouser($otherid, nameforuid($user_id, $app), $bond_id, $message); 
			return $app->json(array("message" => "Success."), 200);
		}
	}

	return $app->json(array("message" => "Something went wrong.  Please try again later."), 500); 
})
->before($authMESSAGE);

$app->get('/api/bonds/{id}', function($id) use($app) {
	$st = $app['pdo']->prepare('SELECT bond_id FROM bonds WHERE id1=:id OR id2=:id'); 
	$st->execute(array(':id' => $id));
    $row = $st->fetchAll(PDO::FETCH_ASSOC);
	$bonds = array(); 
	foreach($row as $bond){
		$bonds[] = $bond['bond_id'];
	}

	$names = namesforotherusersinbonds($bonds, $id, $app);
	return $app->json($names, 200); 
})
->before($auth);

$app->post('/api/bondsusers', function(Request $request) use($app) {
	$bond_id = $request->get('bond_id');
	$st = $app['pdo']->prepare('SELECT id1, id2 FROM bonds WHERE bond_id=:bid');
	$st->execute(array(':bid' => $bond_id));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return $app->json($row, 200);	
})
->before($authBONDID);

$app->post('/api/bonds', function(Request $request) use($app) {
	$id1 = $request->get('id1'); 
	$id2 = $request->get('id2');
	return createbond($id1, $id2, $app);
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
    $st = $app['pdo']->prepare('SELECT id, file FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "No image was found for the given identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})
-> before($authAny); 

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

function doesexist($phone, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE phone=:phone');
    $st->execute(array(':phone' => $phone));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    if($st->rowCount() > 0) {
        return true;
    }
    return false;
}

$app->get('/api/check/{phone}', function($phone) use($app) {
	return $app->json(array("message" => doesexist($phone, $app)), 200); 
}); 

$app->delete('/api/users/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM users WHERE id=:id');
    $st->execute(array(':id' => $id));
    return $app->json(array("message" => "success"), 200); 
})->before($auth); 

$app->get('/api/users/{id}', function(Request $request) use($app) {
    $st = $app['pdo']->prepare('SELECT * FROM users WHERE id=:id');
	$id = $request->get('id'); 
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);

	$auth = $request->headers->get('x-auth-key'); 

	if($row["auth_key"] !== $auth) {
		$allowed = array("name", "id");
		foreach($row as $prop => $val) {
			if(!in_array($prop, $allowed)){
				unset($row[$prop]);
			}
		}
	}

    unset($row["auth_key"]); 
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "Please provide a valid identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})->before($authAny); 

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

	if(empty($phone) || empty($password)){
		return $app->json(array("error" => "Invalid parameters."), 400); 
	}

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
	$app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$id = $request->get('id');
    $name = $request->get('name');
//    $email = $request->get('email');
    $phone = $request->get('phone');
    $password = $request->get('password');
    $age = $request->get('age');
    $gender = $request->get('gender');
	$relationship = $request->get('relationship');

    $valid = array();
    $valid["name"] = v::string()->length(1,32)->validate($name);
    $valid["relationship"] = v::string()->length(1,30)->validate($relationship);
    $valid["password"] = v::string()->length(1,64)->validate($password);
//    $valid["email"] = v::email()->validate($email);
    $valid["phone"] = v::phone()->validate($phone); 
    $valid["age"] = v::numeric()->validate($age);
	
	$gender = strtolower($gender);
	if($gender == "male" || $gender == "female"){
		$valid["gender"] = 1; 	
	} else {
		$valid["gender"] = 0;
	}

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
        if(doesexist($phone, $app)) {
            return $app->json(array("error" => "An account with the given information already exists."), 409); 
        }
        
        // email does not exist, continue to create user 

        // hash the password
        $password = password_hash($password, PASSWORD_DEFAULT);
       
		// generate auth key seed with phone number
		$newkey = generatekey($phone, $app);

        // insert into the database 
        $st = $app['pdo']->prepare("INSERT INTO users(name, phone, age, password, auth_key, gender, relationship) VALUES(:name, :phone, :age, :password, :key, :gender, :relationship) RETURNING id");
        $st->execute(array('name' => $name, 'phone' => $phone, 'age' => $age, 'password' => $password, 'key' => $newkey, 'gender' => $gender, 'relationship' => $relationship));
        
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
