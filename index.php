<?php

/* index.php
 * Author: Misha
 *
 * This script requests and displays game info from Sub Rosa gane servers. 
 *
 * Since the master server requires authentication before it can send an IP list, 
 * all the servers IP's and port's must be obtained manually in-game.
*/

// Useful function for packing an array into an array of bytes
function array_pack(array $arr)
{
	return call_user_func_array("pack", array_merge(array(
		"C*"
	), $arr));
}

if (isset($_GET['format']))
{
	if ($_GET['format'] == 'json')
	{
		if (isset($_GET['pretty']))
		{
			if ($_GET['pretty'] == 'true')
			{
				header('Content-Type: application/json');
			}
		}
		else
		{
			header('Content-Type: application/json');
		}
	}
}

$broadcast_addr = '216.55.186.104';
$broadcast_port = 27590;

// TODO: Parse json file with list of servers.
$known_servers = array(
	array(
		'address' => '216.55.186.104',
		'port' => 27584
	), // Sub Rosa Round
	array(
		'address' => '216.55.186.104',
		'port' => 27583
	), // Sub Rosa World
	array(
		'address' => '216.55.186.104',
		'port' => 27587
	), // Alpha 26 Test Server
);

// Socket timeout is in milliseconds.
$timeout_ms = 1500;

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array(
	'sec' => 0,
	'usec' => ($timeout_ms * 100)
));

// Raw contents to dump into cache file
$cache_filecontents = "";
// Cache file name
$cache_filename     = "cache.txt";
// Caching time in seconds
$cache_life         = '420';

/* Send this header to request list of servers
 *  Sub Rosa: "7DFPJ"
 *    7DFP = packet header (4 bytes)
 *    J    = packet type   (1 byte)
 * $request_list_header = b"7DFPJ";
*/

/* Since we cant simply request a list servers without authing first,
 * we gotta directly request info from each game server using the Hockey?
 * packet header.
 */
//  Hockey?:  "Hock!"
$request_list_header = b"Hock!";

// Our server list will be stored here.
$server_list = array();

// Parse info received from a single server
function parse_server_info($data, $time)
{
	$byteArray = unpack('C*', $data);
	// original format in python = "<BIBB": little endian.. unsigned char, unsigned int, unsigned char, unsigned char
	$varArray  = array_slice($byteArray, 5, 1 + 4 + 1 + 1 + 1 + 32);
	
	$srv_identifier = array_pack(array_slice($byteArray, 0, 4));
	$srv_version    = $varArray[0];
	$srv_stamp      = unpack('V', pack('C*', $varArray[1], $varArray[2], $varArray[3], $varArray[4]));
	$srv_stamp      = $srv_stamp[1];
	
	/* Really.. srv_gametype, players and size are all lies for what they're named.
	 * Everything is so densly packed that it takes effort to extract a few bytes.
	 * They're really just: byte 1, 2, 3... To be later extracted via bitwise math
	 */
	$srv_gametype   = unpack('C', pack('C', $varArray[5]));
	$srv_gametype   = $srv_gametype[1];
	$srv_players    = $varArray[6];
	$srv_size       = $varArray[7];
	
	/* py: version, server_stamp, players, size = struct.unpack("<BIBB", view[5:12])
	*
	* get 3 bytes of data for game type, amount of players, and max players
	* 1: X1X1GGGG
	* 2: P2P2X2X2
	* 3: 0000P1P1
	*
	* then get binary numbers:
	*
	* amount of players connected = X2X2X1X1
	* gametype = 0000GGGG
	* max num of players = P1P10000
	* ????? = 0000P2P2
	*/
	
	/* Just some debugging info */
	$bin1 = sprintf("%08d ", decbin($srv_gametype));
	$bin2 = sprintf("%08d ", decbin($srv_players));
	$bin3 = sprintf("%08d ", decbin($srv_size));
	
	// Ping is basically useless if we're gonna drop the connection after one request
	$srv_ping = $time - $srv_stamp;

	if ($srv_ping < 0)
	{
		$srv_ping += 0xffffffff;
	}
	$bytes = array();
	
	// We must start from the 14th offset to access the server name
	$count = count($byteArray);
	for ($i = 14; $i < $count; $i++)
	{
		if ($byteArray[$i] === 0)
			break;
		array_push($bytes, $byteArray[$i]);
	}
	$srv_name = implode(array_map("chr", $bytes));
	
	/* Dont touch this -- Dangerous bitwise math ahead */
	$currPlayersNibLo = ($srv_gametype >> 4) & 0x0F;				// Extract the low nibble from the first byte
	$currPlayersNibHi = ($srv_players) & 0x0F;						// Extract the high nibble from the second byte
	
	$currPlayers = ($currPlayersNibHi << 4) | ($currPlayersNibLo);	// Combine both high and low nibbles
	
	$totPlayersNibLo = ($srv_players >> 4) & 0x0F;					// Extract the low nibble from the second byte
	$totPlayersNibHi = ($srv_size) & 0x0F;							// Extract the high nibble from the third byte
	
	$maxPlayers = ($totPlayersNibHi << 4) | ($totPlayersNibLo);		// Combine both high and low nibbles
	
	$gameType = $srv_gametype & 0x0F;								// We just need the high nibble from the first byte
	
	// Our final result.. finally!
	$result = array(
		'ident' => $srv_identifier,
		'version' => $srv_version,
		'players' => $currPlayers,
		'size' => $maxPlayers,
		'ping' => $srv_ping,
		'type' => $gameType,
		'stamp' => $srv_stamp,
		'my_stamp' => $time,
		'name' => $srv_name,
		
		'debug' => array(
			'bin1' => $bin1,
			'bin2' => $bin2,
			'bin3' => $bin3
		)
	);
	return $result;
}

// Parse data received from the master server, then return an array of address:port
function parse_server_list($data)
{
	$result = array();
	
	$byteArray = unpack('C*', $data);
	$numAddr   = array_slice($byteArray, 5, 4);
	$numAddr   = unpack('V', pack('C*', $numAddr[0], $numAddr[1], $numAddr[2], $numAddr[3]));
	$numAddr   = $numAddr[1];
	
	// 6 = four chars (4) + one short (2)
	$byteCount = 6;
	
	// Start at offset 9
	$x = 9;
	
	// Loop through the amount of servers
	for ($i = 0; $i < $numAddr; $i++)
	{
		
		$address_array = array_slice($byteArray, $x, 4);
		
		// Reverse the order of ip octets
		$address_array = array_reverse($address_array);
		
		// Pack both bytes of the port into one short
		$address_port = array_slice($byteArray, $x + 4, 2);
		$address_port = unpack('v', pack('C*', $address_port[0], $address_port[1]));
		$address_port = $address_port[1];
		
		// Convert address array into a ip address string
		$address_string = implode('.', $address_array);

		$serverAddress = array(
			'address' => $address_string,
			'port' => $address_port
		);
		
		array_push($result, $serverAddress);
		
		$x += $byteCount;
	}
	return $result;
}

function create_server_info_request($version)
{
	// Hockey?: "Hock\x00" + game version (55) + 4 byte timestamp
	//  return pack('C*', 72, 111, 99, 107, 0, $version, 0,0,0,0); // hockey
	
	// Sub Rosa: "7DFP\x00" + game version (25) + 4 byte timestamp
	// This is simply the decimal form of the packet header...
	return pack('C*', 55, 68, 70, 80, 0, $version, 0, 0, 0, 0); // sub rosa
}

function change_server_info_timestamp(&$data, $stamp)
{
	$stampArray = unpack('C*', pack('V', $stamp));
	
	array_splice($data, 6, 4, $stampArray);
}

function get_millis_truncated()
{
	return (int) (round(time() * 1000)) & 0xffffffff;
}

function get_server_info(&$sock, $ip, $port, $version, $max_attempts = 3)
{
	$requestArray = unpack('C*', create_server_info_request($version));
	$requestLen   = count($requestArray);
	
	$attempts = 0;
	
	$serverInfo = array();
	
	// We're going to attempt to ask the server for info a certain amount of times.
	while ($attempts < $max_attempts)
	{
		$attempts += 1;
		$timeCurr = get_millis_truncated();
		change_server_info_timestamp($requestArray, $timeCurr);
		
		$requestBuf = array_pack($requestArray);
		socket_sendto($sock, $requestBuf, 10, 0, $ip, $port);
		
		while (true)
		{
			$ret = socket_recvfrom($sock, $dataBuf, 256, 0, $ip, $port);
			//echo socket_strerror(socket_last_error($sock)); 

			$serverInfo = parse_server_info($dataBuf, $timeCurr);
			if ($serverInfo['version'])
				break;
			if (!$ret)
				break;
		}
	}
	if (empty($serverInfo) || !$serverInfo['version'])
		return false;
	else
		return $serverInfo;
}

///////////////////////////////
// send master list request
///////////////////////////////
/* socket_sendto ($socket, $request_list_header, strlen($request_list_header), 0, $broadcast_addr, $broadcast_port);
*
* while(true)
* {
* 	$ret = socket_recvfrom($socket, $data, 256, 0, $broadcast_addr, $broadcast_port);
* 	echo socket_strerror(socket_last_error($socket)); 
*
* 	$status = socket_get_status($socket);
*
* 	var_dump(unpack('C*', $data));
* 	var_dump($status);
*
*
* 	$server_list = parse_server_list($data);
*
* 	var_dump($server_list);
*
* 	if(!$ret) break;
*
* }
*
* $server_list = parse_server_list($data);
*/

$json_data = array();

$newCacheTime = false;

$filemtime = @filemtime($cache_filename); // returns FALSE if file does not exist

if (!$filemtime or (time() - $filemtime >= $cache_life))
{
	$newCacheTime = true;
}
else
{
	$cache_filecontents = file_get_contents($cache_filename);
	$newCacheTime       = false;
}

if ($newCacheTime)
{
	// We're using a cache file.
	foreach ($known_servers as $server)
	{
		$info = array();
		$info = get_server_info($socket, $server['address'], $server['port'], 25);
		
		if ($info)
		{			
			$array = array(
				'address' => $server['address'],
				'port' => $server['port'],
				'info' => $info
			);
			
			array_push($json_data, $array);
			
		}
	}
	
	file_put_contents($cache_filename, json_encode($json_data));
}
else
{
	// Otherwise we're not using a cache file, we just requested fresh info.
	$json_data = json_decode($cache_filecontents, true);
}

// Have we received any GET parameters? Lets handle them!
if (isset($_GET['format']))
{
	if ($_GET['format'] == 'json')
	{
		if (isset($_GET['pretty']))
		{
			if ($_GET['pretty'] == 'true')
			{
				$json_data = json_encode($json_data, JSON_PRETTY_PRINT);
				echo ($json_data);
				die();
			}
		}
		
		$json_data = json_encode($json_data);
		echo ($json_data);
		die();
	}
}
/* End PHP script */
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Sub Rosa Server List</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!--<link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css"> -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/darkly/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
  <script src="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
/* Sticky footer styles
-------------------------------------------------- */
html {
  position: relative;
  min-height: 100%;
}
body {
  /* Margin bottom by footer height */
  margin-bottom: 60px;
}
.footer {
  position: absolute;
  bottom: 0;
  width: 100%;
  /* Set the fixed height of the footer here */
  height: 60px;
  background-color: #3d3d3d;
}


/* Custom page CSS
-------------------------------------------------- */
/* Not required for template or sticky footer method. */

body > .container {
  padding: 60px 15px 0;
}
.container .text-muted {
  margin: 20px 0;
}

.footer > .container {
  padding-right: 15px;
  padding-left: 15px;
}

code {
  color: #f94171;
  background-color: #3c292e;
  font-size: 80%;
}
</style>
  
</head>
<body>

<div class="container">
	<a href="https://github.com/LiquidProcessor/subrosa-server-list"><img style="position: absolute; top: 0; right: 0; border: 0;" src="https://camo.githubusercontent.com/365986a132ccd6a44c23a9169022c0b5c890c387/68747470733a2f2f73332e616d617a6f6e6177732e636f6d2f6769746875622f726962626f6e732f666f726b6d655f72696768745f7265645f6161303030302e706e67" alt="Fork me on GitHub" data-canonical-src="https://s3.amazonaws.com/github/ribbons/forkme_right_red_aa0000.png"></a>
	
	<h2>Sub Rosa Server List </h2> <span class="label label-primary">Cache time: <?php echo (floor($cache_life / 60)); ?>m</span>
	
	<p>This is a work in progress website, please report any bugs on the <a href="https://www.reddit.com/r/subrosa/comments/501fgz/sub_rosa_server_list_page_now_for_public_use/">Reddit post</a>.</p>
	<p>To get JSON output, pass this GET parameter to the same page: <code>?format=json</code>. Pretty print it by appending <code>&amp;pretty=true</code>.</p>
	<table class="table table-striped">
		<thead>
			<tr>
			<th>Name</th>
			<th>Address</th>
			<th>Port</th>
			<th>Players</th>
			<th>Gamemode</th>
			<th>Version</th>
		</tr>
	</thead>
	<tbody>
	<?php
foreach ($json_data as $server)
{
	$type = 'World';
	if ($server['info']['type'] == 3)
		$type = 'Round';
	if ($server['info']['type'] == 2)
		$type = 'Race';
	
	echo ("<tr>
				<td>" . $server['info']['name'] . "</td>
				<td>" . $server['address'] . "</td>
				<td>" . $server['port'] . "</td>
				<td>" . $server['info']['players'] . '/' . $server['info']['size'] . "</td>
				<td>" . $type . "</td>
				<td>" . $server[info]['version'] . "</td>
			</tr>");
}
?>
	</tbody>
	</table>
</div>


<footer class="footer">
	<div class="container">
		<p class="text-muted">Created by <a href="https://www.reddit.com/user/hontro/">/u/hontro</a>.</p>
	</div>
</footer>

</body>
</html>

