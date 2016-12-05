<?php
//Version 0.6 - 5 Dic 2016
/*
	This script was made by Enrico `tpaper' Ronconi in Nov 2014
	<ronconi.enrico@yahoo.it>
*/

/*
	This program is free software: you can redistribute it and/or modify it
	under the terms of the GNU General Public License as published by the
	Free Software Foundation, either version 3 of the License, or (at your
	option) any later version.

    This program is distributed in the hope that it will be useful, but
	WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
	General Public License for more details.

    You should have received a copy of the GNU General Public License along
	with this program. If not, see <http://www.gnu.org/licenses/>.
*/

if( $argc < 2 ){
	die("Usage: php ".$argv[0]." group-id [mysql-table-name]\n");
}

//-------------------------------------
//	GENERAL SETTINGS
//-------------------------------------

$verbose = true;						//If true, displays debug messages
$show_urls = true;						//If true, displays url of every https request
$log_filename = 'get_data.log';			//Log file name, IF EXISTING, IT WILL BE DELETED
$out_filename = 'out.csv';				//Output file name
$mysql_auto = true;						//If true, the script imports this data into a mysql table automatically
$post_limit = false;					//Max number of post stored. If set to false, it will continue until the feed ends

if( $mysql_auto && ($argc < 3)) {
	die('Since $mysql_auto is true, you must provide the table name or set $mysql_auto to false'."\nUsage: php ".$argv[0]." group-id [mysql-table-name]\n");
}

//You must provide a valid Facebook access token of a user that have access to the group
$fb_access_token = '';
$fb_group_id = $argv[1];	//Target group id
$fb_api_ver = '2.8';		//Graph API version to use. IT'S VERY IMPORTANT, please read the 'permissions' part of the readme file

if(floatval($fb_api_ver) > 2.59) {
	$reactions = true; 
	logthis("Reactions count enabled");
} else $reactions = false; //If api >= 2.6 enables reaction support

//-------------------------------------
//	MySQL OPTIONS
//-------------------------------------
//	Necessary only if $mysql_auto is set to true.

$MYSQL_host = 'localhost';					//Where the MySQL server is hosted
$MYSQL_port = '3306';						//MySQL server port
$MYSQL_username = 'php';					//MySQL username. Obviously that user must have permission to insert data into $MYSQL_table
$MYSQL_password = '';		//If you need explanation for this var, you have to consider the idea of deleting this script from your pc and go swimming
$MYSQL_db = 'fbstat';						//MySQL database name
//$MYSQL_table = '';						//MySQL table name - now from argument 2
$MYSQL_table = $argv[2];

$MYSQL_fields_list = ['ID','uid','uname','type','date','ln','cn','fc_uid','fc_uname']; //Fields name in the MySQL table. This order MUST be the same of the order in the parse_and_write function.
/*
	Table recommended format:
	
	+----------+-------------+------+-----+---------+-------+
	| Field    | Type        | Null | Key | Default | Extra |
	+----------+-------------+------+-----+---------+-------+
	| ID       | varchar(33) | NO   | PRI | NULL    |       | post id
	| uid      | varchar(17) | NO   |     | NULL    |       | user id
	| uname    | tinytext    | NO   |     | NULL    |       | user name
	| type     | varchar(10) | NO   |     | NULL    |       | post type
	| date     | datetime    | NO   |     | NULL    |       | date
	| ln       | smallint(6) | NO   |     | NULL    |       | likes/reactions count
	| cn       | smallint(6) | NO   |     | NULL    |       | comments count
	| fc_uid   | varchar(17) | YES  |     | NULL    |       | first comment user id
	| fc_uname | tinytext    | YES  |     | NULL    |       | first comment user name
	+----------+-------------+------+-----+---------+-------+
*/	

//-------------------------------------
//	ADVANCED SETTINGS
//-------------------------------------
//	If not sure, just don't touch this. In most cases you don't have to
//	modify this in order to run the script.

/*
List of the fields that the script will ask to Facebook. Notice that ADD a
field DOESN'T MAKE the script save that field value. If you want to store
another field you have to modify this parameter and the functions
parse_and_write and mysql_import.
*/
if($reactions){
	$fb_fileds = ['id','from','created_time','type','reactions.summary(true).limit(0)','comments.summary(true).limit(1)']; //with reactions
} else {
	$fb_fileds = ['id','from','created_time','type','likes.summary(true).limit(0)','comments.summary(true).limit(1)']; //without reactions
	
}
//default: $fb_fileds = ['id','from','created_time','type','reactions.summary(true).limit(0)','comments.summary(true).limit(1)']; with reactions,
// $fb_fileds = ['id','from','created_time','type','likes.summary(true).limit(0)','comments.summary(true).limit(1)']; without reactions

/*
Number of post asked per http request. Since Facebook limits the amount of
data that you can retrieve from its servers in one request, if you set this
value too big Facebook will be very angry and will reply your http request
with a "fuck off" instead of the data wanted. And nothing will work
*/
$fb_limit = 350;
//default: $fb_limit = 350;

$http_tries = 10;			//Number of http tries before give up
$http_delay = 100;			//Delay between http request tries (mills)

$table_record_sep = ',';	//Fields separator in the csv file
$table_row_sep = "\n";		//Row separator in the csv file

//Escaped version of previous separators: (for MySQL importing)
$table_record_sep_esc = ',';
$table_row_sep_esc = "\\n";

//-------------------------------------
//	END OF SETTINGS
//-------------------------------------

function logthis($message){
	/*
	Log the message with timestamp into the file handled by $log_hande.
	If $log_handle is null, no log is written.
	If $verbose is set to true, it also displays the message.
	*/
	
	global $verbose,$log_handle;
	
	$micros = microtime();
	$micros = explode(" ",$micros);
	$micros = explode('.',$micros[0]);
	$micros = $micros[1];
	$timestamp = date("d M Y H:i:s");
	$timestamp = $timestamp.'.'.substr($micros,0,1);
	
	$message = $timestamp." :: ".$message."\n";
	if($verbose) echo($message);
	if(is_null($log_handle)) return;
	fwrite($log_handle,$message);
	return;
}

function build_url($token,$fields,$limit,$group_id){
	/*
	Given an access token, an array with fields list, an integer limit and
	a group id this function builds an URL to query the graph API.
	*/

	global $fb_api_ver;

	return("https://graph.facebook.com/v$fb_api_ver/$group_id/feed?fields=".implode(',',$fields)."&limit=$limit&access_token=$token");
}

function http_request($URL,$max_tries,$delay = 100){
	/*
	Send HTTP request and retrieve the response. If get an http error, it
	tries again $max_tries times every $delay milliseconds
	*/
	global $show_urls,$curl_handle;
	$tries = 0;
	$out = false;
	while(($tries < $max_tries) && ($out == false)){
		$tries++;
		if($show_urls) logthis("tryng to open $URL (try $tries of $max_tries)..."); else logthis("asking for data (try $tries of $max_tries)...");


		//$out = @file_get_contents($URL);
		curl_setopt($curl_handle, CURLOPT_URL, $URL);
		$out = curl_exec($curl_handle);
		if($out[2] == 'e') $out = false;

		if($out == true) {
			logthis("Success!");
			return $out;
		} else logthis("Failed to open URL.");
		usleep($delay * 1000);
	}
	
	return $out;	
}

function writeout($record,$out_handle){
	/*
	Write out on a file a record;
	*/
	global $table_record_sep,$table_row_sep;
	fwrite($out_handle,implode($table_record_sep,$record).$table_row_sep);
}

function parse_and_write($json_response,$out_handle){
		/*
		This function parse the json code and writes on file a CVS table
		with the data.
		Returns the URL pointer to the next "page"
		*/
		global $stored,$post_limit,$table_record_sep,$reactions;
		
		if(!$json_response) return false;
		$out = json_decode($json_response);

		foreach($out->{'data'} as $post){
			$post_id = $post->{'id'};
			if(property_exists($post,'from')){
				$user_id = $post->{'from'}->{'id'};
				$user_name = str_replace($table_record_sep,' ',$post->{'from'}->{'name'});
			} else {
				$user_id = 'N/A';
				$user_name = 'N/A';
			}
			$type = $post->{'type'};
			$created = explode('+',$post->{'created_time'});
			if($reactions){
				$likes = $post->{'reactions'}->{'summary'}->{'total_count'};
			} else {
				$likes = $post->{'likes'}->{'summary'}->{'total_count'};
			}
			$comments = $post->{'comments'}->{'summary'}->{'total_count'};
			if(isset($post->{'comments'}->{'data'}[0])) {
				$fc = $post->{'comments'}->{'data'}[0];
				$fc_user_id = $fc->{'from'}->{'id'};
				$fc_user_name = $fc->{'from'}->{'name'};
			} else {
				$fc_user_id = null;
				$fc_user_name = null;
			}
			writeout([$post_id,$user_id,$user_name,$type,$created[0],$likes,$comments,$fc_user_id,$fc_user_name],$out_handle);
			$stored++;
			if(($stored >= $post_limit) && $post_limit) return false;
		}
		
		if(isset($out->{'paging'}->{'next'})) return $out->{'paging'}->{'next'};
		return false;
}

function mysql_import($input_filename){
	global $MYSQL_host, $MYSQL_username, $MYSQL_password, $MYSQL_db, $MYSQL_port, $MYSQL_table, $table_record_sep_esc, $table_row_sep_esc, $MYSQL_fields_list;
	
	logthis("Opening MySql connection...");
	$MyHandle = @new mysqli($MYSQL_host, $MYSQL_username, $MYSQL_password, $MYSQL_db, $MYSQL_port);
	if($MyHandle->connect_error) {
		$mess = 'Error while connection (ErrNo: '.$MyHandle->connect_errno.' - '.$MyHandle->connect_error.")!\nCannot connect to database!";
		logthis($mess);
		return;
	}
	$MyHandle->set_charset("utf8");
	
	logthis("Executing LOAD DATA query...");
	
	$out = $MyHandle->query("LOAD DATA LOCAL INFILE '$input_filename' INTO TABLE $MYSQL_table FIELDS TERMINATED BY '$table_record_sep_esc' LINES TERMINATED BY '$table_row_sep_esc' (".implode(",",$MYSQL_fields_list).")");
	
	if(!$out) {
		logthis("Error while importing data into MySQL table! ".$MyHandle->error);
		return;
	}
	
	logthis("Imported! ".$MyHandle->warning_count." warnings.");
	
	$MyHandle->close();
}

$log_handle = fopen($log_filename,"w");		//Open log file
$outfile = fopen($out_filename,"w");		//Open csv out file
$curl_handle = curl_init();
curl_setopt($curl_handle, CURLOPT_HEADER, 0);  
curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);  

logthis("Hi!");								//Welcome message

logthis("Collecting data and saving to file...");
$stored = 0;
$next = build_url($fb_access_token,$fb_fileds,$fb_limit,$fb_group_id);	//First URL
while($next) {
	$next = parse_and_write(http_request($next,$http_tries,$http_delay),$outfile);	//Loop until feed end
	echo("Stored $stored posts\n");
}
fclose($outfile);														//Close output file
logthis("$stored posts was saved successfully");

if($mysql_auto) mysql_import($out_filename);

logthis("bye!");		//Goodbye message
curl_close($curl_handle);
fclose($log_handle);	//Close log file
?>
