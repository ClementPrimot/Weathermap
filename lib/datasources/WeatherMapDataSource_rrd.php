<?php
// RRDtool datasource plugin.
//     gauge:filename.rrd:ds_in:ds_out
//     filename.rrd:ds_in:ds_out
//     filename.rrd:ds_in:ds_out
//

include_once(dirname(__FILE__)."/../ds-common.php");
include_once(dirname(__FILE__)."/../database.php");

class WeatherMapDataSource_rrd extends WeatherMapDataSource {

	function Init(&$map)
	{
		global $config;
		#if (extension_loaded('RRDTool')) // fetch the values via the RRDtool Extension
		#{
	#		debug("RRD DS: Using RRDTool php extension.\n");
#			return(TRUE);
#		}
#		else
#		{
			if($map->context=='cacti')
			{
				wm_debug("RRD DS: path_rra is ".$config["rra_path"]." - your rrd pathname must be exactly this to use poller_output\n");
				// save away a couple of useful global SET variables
				$map->add_hint("cacti_path_rra",$config["rra_path"]);
				$map->add_hint("cacti_url",$config['url_path']);
			}
			if (file_exists($map->rrdtool)) {
				if((function_exists('is_executable')) && (!is_executable($map->rrdtool)))
				{
					wm_warn("RRD DS: RRDTool exists but is not executable? [WMRRD01]\n");
					return(FALSE);
				}
				$map->rrdtool_check="FOUND";
				return(TRUE);
			}
			// normally, DS plugins shouldn't really pollute the logs
			// this particular one is important to most users though...
			if($map->context=='cli')
			{
				wm_warn("RRD DS: Can't find RRDTOOL. Check line 29 of the 'weathermap' script.\nRRD-based TARGETs will fail. [WMRRD02]\n");
			}
			if($map->context=='cacti')
			{    // unlikely to ever occur
				wm_warn("RRD DS: Can't find RRDTOOL. Check your Cacti config. [WMRRD03]\n");
			}
#		}

		return(FALSE);
	}

	function Recognise($targetstring)
	{
		if(preg_match("/^(.*\.rrd):([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_]+)$/",$targetstring,$matches))
		{
			return TRUE;
		}
		elseif(preg_match("/^(.*\.rrd)$/",$targetstring,$matches))
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	function wmrrd_read_from_poller_output($rrdfile,$cf,$start,$end,$dsnames, &$data, &$map, &$data_time, &$item)
	{
		global $config;

		wm_debug("RRD ReadData: poller_output style\n");

		if(isset($config))
		{
		    $pdo = weathermap_get_pdo();

			// take away the cacti bit, to get the appropriate path for the table
			// $db_rrdname = realpath($rrdfile);
			$path_rra = $config["rra_path"];
			$db_rrdname = $rrdfile;
			$db_rrdname = str_replace($path_rra,"<path_rra>",$db_rrdname);
			wm_debug("******************************************************************\nChecking weathermap_data\n");
			foreach (array(IN,OUT) as $dir)
			{
				wm_debug("RRD ReadData: poller_output - looking for $dir value\n");
				if($dsnames[$dir] != '-')
				{
					wm_debug("RRD ReadData: poller_output - DS name is ".$dsnames[$dir]."\n");

//					$SQL = "select * from weathermap_data where rrdfile='".mysql_real_escape_string($db_rrdname)."' and data_source_name='".mysql_real_escape_string($dsnames[$dir])."'";

//					$SQLcheck = "select data_template_data.local_data_id from data_template_data,data_template_rrd where data_template_data.local_data_id=data_template_rrd.local_data_id and data_template_data.data_source_path='".mysql_real_escape_string($db_rrdname)."' and data_template_rrd.data_source_name='".mysql_real_escape_string($dsnames[$dir])."'";
//					$SQLvalid = "select data_template_rrd.data_source_name from data_template_data,data_template_rrd where data_template_data.local_data_id=data_template_rrd.local_data_id and data_template_data.data_source_path='".mysql_real_escape_string($db_rrdname)."'";

                    $statement_check = $pdo->prepare("select data_template_data.local_data_id from data_template_data,data_template_rrd where data_template_data.local_data_id=data_template_rrd.local_data_id and data_template_data.data_source_path=? and data_template_rrd.data_source_name=?");
                    $statement_valid = $pdo->prepare("select data_template_rrd.data_source_name from data_template_data,data_template_rrd where data_template_data.local_data_id=data_template_rrd.local_data_id and data_template_data.data_source_path=?");
                    $statement_search = $pdo->prepare("select * from weathermap_data where rrdfile=? and data_source_name=?");

                    $statement_search->execute(array($db_rrdname, $dsnames[$dir]));
                    $result = $statement_search->fetch(PDO::FETCH_ASSOC);

                    $worst_time = time() - 8*60;
//					$result = db_fetch_row($SQL);

					// OK, the straightforward query for data failed, let's work out why, and add the new data source if necessary
					if(!isset($result['id']))
					{
						wm_debug("RRD ReadData: poller_output - Adding new weathermap_data row for $db_rrdname:".$dsnames[$dir]."\n");

                        $statement_check->execute(array($db_rrdname,$dsnames[$dir]));
                        $result = $statement_check->fetch(PDO::FETCH_ASSOC);
//						$result = db_fetch_row($SQLcheck);
						if(!isset($result['local_data_id']))
						{
							$fields = array();

                            $statement_valid->execute(array($db_rrdname));
                            $results = $statement_valid->fetchAll(PDO::FETCH_ASSOC);
//                            $results = db_fetch_assoc($SQLvalid);
							foreach ($results as $result)
							{
								$fields[] = $result['data_source_name'];
							}
							if(count($fields) > 0)
							{
								wm_warn("RRD ReadData: poller_output: ".$dsnames[$dir]." is not a valid DS name for $db_rrdname - valid names are: ".join(", ",$fields)." [WMRRD07]\n");
							}
							else
							{
								wm_warn("RRD ReadData: poller_output: $db_rrdname is not a valid RRD filename within this Cacti install. <path_rra> is $path_rra [WMRRD08]\n");
							}
						}
						else
						{
							// add the new data source (which we just checked exists) to the table.
							// Include the local_data_id as well, to make life easier in poller_output
							// (and to allow the cacti: DS plugin to use the same table, too)
                            wm_debug("RRD ReadData: poller_output - Adding new weathermap_data row for data source ID " . $result['local_data_id'] . "\n");
                            $statement_insert = $pdo->prepare("INSERT INTO weathermap_data (rrdfile, data_source_name, sequence, local_data_id) VALUES (?,?, 0,?)");
                            $statement_insert->execute(array($db_rrdname, $dsnames[$dir], $result['local_data_id']));

//							$SQLins = "insert into weathermap_data (rrdfile, data_source_name, sequence, local_data_id) values ('".mysql_real_escape_string($db_rrdname)."','".mysql_real_escape_string($dsnames[$dir])."', 0,".$result['local_data_id'].")";
//							db_execute($SQLins);
						}
					}
					else
					{	// the data table line already exists
						wm_debug("RRD ReadData: poller_output - found weathermap_data row\n");
						// if the result is valid, then use it
						if( ($result['sequence'] > 2) && ( $result['last_time'] > $worst_time) )
						{
							$data[$dir] = $result['last_calc'];
							$data_time = $result['last_time'];
							wm_debug("RRD ReadData: poller_output - data looks valid\n");
						}
						else
						{
							$data[$dir] = 0;
							wm_debug("RRD ReadData: poller_output - data is either too old, or too new\n");
						}
						// now, we can use the local_data_id to get some other useful info
						// first, see if the weathermap_data entry *has* a local_data_id. If not, we need to update this entry.
						$ldi = 0;
						if(!isset($result['local_data_id']) || $result['local_data_id']==0)
						{
                            $statement_check->execute(array($db_rrdname,$dsnames[$dir]));
                            $r2 = $statement_check->fetch(PDO::FETCH_ASSOC);
//							$r2 = db_fetch_row($SQLcheck);
							if(isset($r2['local_data_id']))
							{
								$ldi = $r2['local_data_id'];
								wm_debug("RRD ReadData: updated  local_data_id for wmdata.id=" . $result['id'] . "to $ldi\n");
								// put that in now, so that we can skip this step next time
                                $statement_update = $pdo->prepare("update weathermap_data set local_data_id=? where id=?");
                                $statement_update->execute(array($r2['local_data_id'], $result['id']));

//								db_execute("update weathermap_data set local_data_id=".$r2['local_data_id']." where id=".$result['id']);

							}
						}
						else
						{
							$ldi = $result['local_data_id'];
						}

						if($ldi>0) UpdateCactiData($item, $ldi);
					}
				}
				else
				{
					wm_debug("RRD ReadData: poller_output - DS name is '-'\n");
				}
			}
		}
		else
		{
			wm_warn("RRD ReadData: poller_output - Cacti environment is not right [WMRRD12]\n");
		}

		wm_debug("RRD ReadData: poller_output - result is ".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT])."\n");
		wm_debug("RRD ReadData: poller_output - ended\n");
	}

	function wmrrd_read_from_php_rrd($rrdfile,$cf,$start,$end,$dsnames, &$data ,&$map, &$data_time,&$item)
	{
		// not yet implemented - use php-rrdtool to read rrd data. Should be quicker
		if ((1==0) && extension_loaded('RRDTool')) // fetch the values via the RRDtool Extension
		{
			// for the php-rrdtool module, we use an array instead...
			$rrdparams = array("AVERAGE","--start",$start,"--end",$end);
			$rrdreturn = rrd_fetch ($rrdfile,$rrdparams,count($rrdparams));
			print_r($rrdreturn);
			// XXX - figure out what to do with the results here
			$now = $rrdreturn['start'];
			$n=0;
			do {
				$now += $rrdreturn['step'];
				print "$now - ";
				for($i=0;$i<$rrdreturn['ds_cnt'];$i++)
				{
					print $rrdreturn['ds_namv'][$i] . ' = '.$rrdreturn['data'][$n++]." ";
				}
				print "\n";
			} while($now <= $rrdreturn['end']);
		}
	}

	# rrdtool graph /dev/null -f "" -s now-30d -e now DEF:in=../rra/atm-sl_traffic_in_5498.rrd:traffic_in:AVERAGE DEF:out=../rra/atm-sl_traffic_in_5498.rrd:traffic_out:AVERAGE VDEF:avg_in=in,AVERAGE VDEF:avg_out=out,AVERAGE PRINT:avg_in:%lf PRINT:avg_out:%lf

	function wmrrd_read_from_real_rrdtool_aggregate($rrdfile,$cf,$aggregatefn,$start,$end,$dsnames, &$data, &$map, &$data_time,&$item)
	{

		wm_debug("RRD ReadData: VDEF style, for ".$item->my_type()." ".$item->name."\n");

		$extra_options = $map->get_hint("rrd_options");

		// Assemble an array of command args.
		// In a real programming language, we'd be able to pass this directly to exec()
		// However, this will at least allow us to put quotes around args that need them
		$args = array();
		$args[] = "graph";
		$args[] = "/dev/null";
		$args[] = "-f";
		$args[] = "''";
		$args[] = "--start";
		$args[] = $start;
		$args[] = "--end";
		$args[] = $end;

		// rrdcached Support: Use daemon
		if ($map->daemon)
		{
			$args[] = "--daemon";
			$args[] = $map->daemon_args;
		}

		# assemble an appropriate RRDtool command line, skipping any '-' DS names.
		# $command = $map->rrdtool . " graph /dev/null -f ''  --start $start --end $end ";

		if($dsnames[IN] != '-')
		{
			# $command .= "DEF:in=$rrdfile:".$dsnames[IN].":$cf ";
			# $command .= "VDEF:agg_in=in,$aggregatefn ";
			# $command .= "PRINT:agg_in:'IN %lf' ";

			$args[] = "DEF:in=$rrdfile:".$dsnames[IN].":$cf";
			$args[] = "VDEF:agg_in=in,$aggregatefn";
			$args[] = "PRINT:agg_in:'IN %lf'";
		}

		if($dsnames[OUT] != '-')
		{
			# $command .= "DEF:out=$rrdfile:".$dsnames[OUT].":$cf ";
			# $command .= "VDEF:agg_out=out,$aggregatefn ";
			# $command .= "PRINT:agg_out:'OUT %lf' ";

			$args[] = "DEF:out=$rrdfile:".$dsnames[OUT].":$cf";
			$args[] = "VDEF:agg_out=out,$aggregatefn";
			$args[] = "PRINT:agg_out:'OUT %lf'";
		}

		$command = $map->rrdtool;
		foreach ($args as $arg)
		{
			if(strchr($arg," ") != FALSE)
			{
				$command .= ' "' . $arg . '"';
			}
			else
			{
				$command .= ' ' . $arg;
			}
		}
		$command .= " " . $extra_options;

		wm_debug("RRD ReadData: Running: $command\n");
		$pipe=popen($command, "r");

		$lines=array ();
		$count = 0;
		$linecount = 0;

		if (isset($pipe))
		{
			// fgets($pipe, 4096); // skip the blank line
			$buffer='';
			$data_ok = FALSE;

			while (!feof($pipe))
			{
				$line=fgets($pipe, 4096);
                // there might (pre-1.5) or might not (1.5+) be a leading blank line
                // we don't want to count it if there is
                if (trim($line) != "") {

                    wm_debug("> " . $line);
                    $buffer .= $line;
                    $lines[] = $line;
                    $linecount++;
                }
			}
			pclose ($pipe);
			if($linecount>1)
			{
				foreach ($lines as $line)
				{
					if(preg_match('/^\'(IN|OUT)\s(\-?\d+[\.,]?\d*e?[+-]?\d*:?)\'$/i', $line, $matches))
					{
						wm_debug("MATCHED: ".$matches[1]." ".$matches[2]."\n");
						if($matches[1]=='IN') $data[IN] = floatval($matches[2]);
						if($matches[1]=='OUT') $data[OUT] = floatval($matches[2]);
						$data_ok = TRUE;
					}
				}
				if($data_ok)
				{
					if($data[IN] === NULL) $data[IN] = 0;
					if($data[OUT] === NULL) $data[OUT] = 0;
				}
			}
			else
			{
				wm_warn("Not enough output from RRDTool. [WMRRD09]\n");
			}
		}
		else
		{
		    $error = error_get_last();
			wm_warn("RRD ReadData: failed to open pipe to RRDTool: ". $error['message']." [WMRRD04]\n");
		}
		wm_debug ("RRD ReadDataFromRealRRDAggregate: Returning (".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT]).",$data_time)\n");

	}

	function wmrrd_read_from_real_rrdtool($rrdfile,$cf,$start,$end,$dsnames, &$data, &$map, &$data_time,&$item)
	{
		wm_debug("RRD ReadData: traditional style\n");

		// we get the last 800 seconds of data - this might be 1 or 2 lines, depending on when in the
		// cacti polling cycle we get run. This ought to stop the 'some lines are grey' problem that some
		// people were seeing

		// NEW PLAN - READ LINES (LIKE NOW), *THEN* CHECK IF REQUIRED DS NAMES EXIST (AND FAIL IF NOT),
		//     *THEN* GET THE LAST LINE WHERE THOSE TWO DS ARE VALID, *THEN* DO ANY PROCESSING.
		//  - this allows for early failure, and also tolerance of empty data in other parts of an rrd (like smokeping uptime)

		$extra_options = $map->get_hint("rrd_options");

		$values = array();
		$args = array();

		#### $command = '"'.$map->rrdtool . '" fetch "'.$rrdfile.'" AVERAGE --start '.$start.' --end '.$end;
		#$command=$map->rrdtool . " fetch $rrdfile $cf --start $start --end $end $extra_options";
		$args[] = "fetch";
		$args[] = $rrdfile;
		$args[] = $cf;
		$args[] = "--start";
		$args[] = $start;
		$args[] = "--end";
		$args[] = $end;

		// rrdcached Support: Use daemon
		if ($map->daemon)
		{
			$args[] = "--daemon";
			$args[] = $map->daemon_args;
		}

		$command = $map->rrdtool;
		foreach ($args as $arg)
		{
			if(strchr($arg," ") != FALSE)
			{
				$command .= ' "' . $arg . '"';
			}
			else
			{
				$command .= ' ' . $arg;
			}
		}
		$command .= " " . $extra_options;


		wm_debug ("RRD ReadData: Running: $command\n");
		$pipe=popen($command, "r");

		$lines=array ();
		$count = 0;
		$linecount = 0;

		if (isset($pipe))
		{
			$headings=fgets($pipe, 4096);
			// this replace fudges 1.2.x output to look like 1.0.x
			// then we can treat them both the same.
			$heads=preg_split("/\s+/", preg_replace("/^\s+/","timestamp ",$headings) );

			//fgets($pipe, 4096); // skip the blank line
			$buffer='';

			while (!feof($pipe))
			{
				$line=fgets($pipe, 4096);
                // there might (pre-1.5) or might not (1.5+) be a leading blank line
                // we don't want to count it if there is
                if (trim($line) != "") {
                    wm_debug("> " . $line);
                    $buffer .= $line;
                    $lines[] = $line;
                    $linecount++;
                }

			}
			pclose ($pipe);

			wm_debug("RRD ReadData: Read $linecount lines from rrdtool\n");
			wm_debug("RRD ReadData: Headings are: $headings\n");

			if( (in_array($dsnames[IN],$heads) || $dsnames[IN] == '-') && (in_array($dsnames[OUT],$heads) || $dsnames[OUT] == '-') )
			{
			    // deal with the data, starting with the last line of output
			     $rlines=array_reverse($lines);

			     foreach ($rlines as $line)
				 {
					 wm_debug ("--" . $line . "\n");
					 $cols=preg_split("/\s+/", $line);
					 for ($i=0, $cnt=count($cols)-1; $i < $cnt; $i++) {
						$h = $heads[$i];
						$v = $cols[$i];
						# print "|$h|,|$v|\n";
						$values[$h] = trim($v);
					}

					$data_ok=FALSE;

					foreach (array(IN,OUT) as $dir)
					{
						$n = $dsnames[$dir];
						# print "|$n|\n";
						if(array_key_exists($n,$values))
						{
							$candidate = $values[$n];
							if(preg_match('/^\-?\d+[\.,]?\d*e?[+-]?\d*:?$/i', $candidate))
							{
								$data[$dir] = $candidate;
								wm_debug("$candidate is OK value for $n\n");
								$data_ok = TRUE;
							}
						}
					}

					if($data_ok)
					{
						// at least one of the named DS had good data
						$data_time = intval($values['timestamp']);

						// 'fix' a -1 value to 0, so the whole thing is valid
						// (this needs a proper fix!)
						if($data[IN] === NULL) $data[IN] = 0;
						if($data[OUT] === NULL) $data[OUT] = 0;

						// break out of the loop here
						break;
					}
			     }
			}
			else
			{
			    // report DS name error
			    $names = join(",",$heads);
			    $names = str_replace("timestamp,","",$names);
			    wm_warn("RRD ReadData: At least one of your DS names (".$dsnames[IN]." and ".$dsnames[OUT].") were not found, even though there was a valid data line. Maybe they are wrong? Valid DS names in this file are: $names [WMRRD06]\n");
			}

		}
		else
		{
            $error = error_get_last();
            wm_warn("RRD ReadData: failed to open pipe to RRDTool: ". $error['message']." [WMRRD04]\n");
		}
		wm_debug ("RRD ReadDataFromRealRRD: Returning (".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT]).",$data_time)\n");
	}

	// Actually read data from a data source, and return it
	// returns a 3-part array (invalue, outvalue and datavalid time_t)
	// invalue and outvalue should be -1,-1 if there is no valid data
	// data_time is intended to allow more informed graphing in the future
	function ReadData($targetstring, &$map, &$item)
	{
		global $config;

		$dsnames[IN] = "traffic_in";
		$dsnames[OUT] = "traffic_out";
		$data[IN] = NULL;
		$data[OUT] = NULL;
		$SQL[IN] = 'select null';
		$SQL[OUT] = 'select null';
		$rrdfile = $targetstring;

		if($map->get_hint("rrd_default_in_ds") != '') {
			$dsnames[IN] = $map->get_hint("rrd_default_in_ds");
			wm_debug("Default 'in' DS name changed to ".$dsnames[IN].".\n");
		}
		if($map->get_hint("rrd_default_out_ds") != '') {
			$dsnames[OUT] = $map->get_hint("rrd_default_out_ds");
			wm_debug("Default 'out' DS name changed to ".$dsnames[OUT].".\n");
		}

		$multiplier = 8; // default bytes-to-bits

		$inbw = NULL;
		$outbw = NULL;
		$data_time = 0;

		if(preg_match("/^(.*\.rrd):([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_]+)$/",$targetstring,$matches))
		{
			$rrdfile = $matches[1];

			$dsnames[IN] = $matches[2];
			$dsnames[OUT] = $matches[3];

			wm_debug("Special DS names seen (".$dsnames[IN]." and ".$dsnames[OUT].").\n");
		}

		if(preg_match("/^rrd:(.*)/",$rrdfile,$matches))
		{
			$rrdfile = $matches[1];
		}

		if(preg_match("/^gauge:(.*)/",$rrdfile,$matches))
		{
			$rrdfile = $matches[1];
			$multiplier = 1;
		}

		if(preg_match("/^scale:([+-]?\d*\.?\d*):(.*)/",$rrdfile,$matches))
		{
				$rrdfile = $matches[2];
				$multiplier = $matches[1];
		}

		wm_debug("SCALING result by $multiplier\n");

		// try and make a complete path, if we've been given a clue
		// (if the path starts with a . or a / then assume the user knows what they are doing)
        // Check for the cases where there is an accidental . in the target
        if(!preg_match("/^(\/)/",$rrdfile)){
		    $fileExist = file_exists($rrdfile);
            if (!$fileExist) {
                $rrdfile_tmp = $rrdfile;
                if(preg_match("/^(\.)/",$rrdfile)){
                    $rrdfile_tmp = substr($rrdfile, 1);
                    if(preg_match("/^(\/)/",$rrdfile_tmp)){
                        $rrdfile_tmp = substr($rrdfile_tmp, 1);
                    }
                }
                $fileExist = file_exists($rrdfile_tmp);
                if(!$fileExist) {
                    $rrdbase = $map->get_hint('rrd_default_path');
                    if ($rrdbase != '') {
                        $rrdfile = $rrdbase . "/" . $rrdfile_tmp;
                    }
                } else{
                    $rrdfile = $rrdfile_tmp;
                }
            }
        }

		$cfname = $map->get_hint('rrd_cf');
		if($cfname=='') $cfname='AVERAGE';

		$period = intval($map->get_hint('rrd_period'));
		if($period == 0) $period = 800;
		$start = $map->get_hint('rrd_start');
		if($start == '') {
		    $start = "now-$period";
		    $end = "now";
		}
		else
		{
		    $end = "start+".$period;
		}

		$use_poller_output = intval($map->get_hint('rrd_use_poller_output'));
		$nowarn_po_agg = intval($map->get_hint("nowarn_rrd_poller_output_aggregation"));
		$aggregatefunction = $map->get_hint('rrd_aggregate_function');

		if($aggregatefunction != '' && $use_poller_output==1)
		{
			$use_poller_output=0;
			if($nowarn_po_agg==0)
			{
				wm_warn("Can't use poller_output for rrd-aggregated data - disabling rrd_use_poller_output [WMRRD10]\n");
			}
		}

		if($use_poller_output == 1)
		{
			wm_debug("Going to try poller_output, as requested.\n");
			WeatherMapDataSource_rrd::wmrrd_read_from_poller_output($rrdfile,"AVERAGE",$start,$end, $dsnames, $data,$map, $data_time,$item);
		}

		// if poller_output didn't get anything, or if it couldn't/didn't run, do it the old-fashioned way
		// - this will still be the case for the first couple of runs after enabling poller_output support
		//   because there won't be valid data in the weathermap_data table yet.
		if( ($dsnames[IN]!='-' && $data[IN] === NULL) || ($dsnames[OUT] !='-' && $data[OUT] === NULL) )
		{
			if($use_poller_output == 1)
			{
				wm_debug("poller_output didn't get anything useful. Kicking it old skool.\n");
			}
			if(file_exists($rrdfile))
			{
				wm_debug ("RRD ReadData: Target DS names are ".$dsnames[IN]." and ".$dsnames[OUT]."\n");

				$values=array();

				if ((1==0) && extension_loaded('RRDTool')) // fetch the values via the RRDtool Extension
				{
					WeatherMapDataSource_rrd::wmrrd_read_from_php_rrd($rrdfile,$cfname,$start,$end, $dsnames, $data,$map, $data_time,$item);
				}
				else
				{
					if($aggregatefunction != '')
					{
						WeatherMapDataSource_rrd::wmrrd_read_from_real_rrdtool_aggregate($rrdfile,$cfname,$aggregatefunction, $start,$end, $dsnames, $data,$map, $data_time,$item);
					}
					else
					{
						// do this the tried and trusted old-fashioned way
						WeatherMapDataSource_rrd::wmrrd_read_from_real_rrdtool($rrdfile,$cfname,$start,$end, $dsnames, $data,$map, $data_time,$item);
					}
				}
			}
			else
			{
				wm_warn ("Target $rrdfile doesn't exist. Is it a file? [WMRRD06]\n");
			}
		}

                // if the Locale says that , is the decimal point, then rrdtool
                // will honour it. However, floatval() doesn't, so let's replace
                // any , with . (there are never thousands separators, luckily)
                //
		if($data[IN] !== NULL)
                {
                    $data[IN] = floatval(str_replace(",",".",$data[IN]));
                    $data[IN] = $data[IN] * $multiplier;
                }
		if($data[OUT] !== NULL)
                {
                    $data[OUT] = floatval(str_replace(",",".",$data[OUT]));
                    $data[OUT] = $data[OUT] * $multiplier;
                }

		wm_debug ("RRD ReadData: Returning (".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT]).",$data_time)\n");

		return( array($data[IN], $data[OUT], $data_time) );
	}
}

// vim:ts=4:sw=4:
