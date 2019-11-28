public function create($l)
 {
   if (!Auth::user()) {
     throw new Exception("Unauthorized");
   }
   $user = Auth::user();
   $user_id = $user->id;
   $is = Instance::where([['user_id', '=', $user_id], ['lesson_id', '=', $l]])->get();
   if (count($is) > 0) {
     throw new InstanceExistsException("Instance for lesson $l and user $user->id already exists");
   }
   $is = Instance::where([['user_id', '=', $user_id]])->get();
   if (count($is) >= $user->quota) {
     throw new QuotaException();
   }
   $fp = fopen(storage_path() . DIRECTORY_SEPARATOR . "port_lock", "r+"); // replace with db lock
   $instance = new Instance();
   $instance->solutionback = "";
   if (flock($fp, LOCK_EX)) {  // acquire an exclusive lock
     $assigned_ports = Instance::select('port')->get()->toArray();
     if (count($assigned_ports) >= 1000) {
       throw new OutOfPortsException();
     } else {
       $system_ports = array();
       for ($i = 9000; $i < 10000; $i++) {
         array_push($system_ports, $i);
       }
       $assigned_ports_array = array();
       for ($i = 0; $i < count($assigned_ports); $i += 1) {
         array_push($assigned_ports_array, $assigned_ports[$i]["port"]);
       }
       $available_ports = array_diff($system_ports, $assigned_ports_array);
       $found = reset($available_ports);
     }
     $instance->user_id = $user_id;
     $instance->lesson_id = $l;
     $instance->port = $found;
     $instance->status = "created";
     $instance->save();
     flock($fp, LOCK_UN);    // release the lock
   } else {
     flock($fp, LOCK_UN);    // release the lock
     fclose($fp);
     throw new LockException();
   }
   fclose($fp);

   $port = $instance->port;
   $id = $instance->id;
   $i = $instance;
   $pfa = $i->path_for_app();
   $dfa = $i->path_for_app();
   $fwp = $i->lesson->framework->path;
   $lp = $i->lesson->path;

   $base = base_path();
   $lessons_path = storage_path() . DIRECTORY_SEPARATOR . 'lessons';
   $instance_path = $lessons_path . DIRECTORY_SEPARATOR . $id;

   $comm = "cd $lessons_path && zicode lesson:pull -f $fwp $lp $id";
   Log::info("Cloning instance $id\n\tCOMMAND: $comm");
   $process = new Process($comm);
   try {
     $process->mustRun();
     $output = $process->getOutput();
     $error = $process->getErrorOutput();
     if ($process->isSuccessful()) {
       Log::info("Cloned $id\n\tOUTPUT: $output\n\tERROR: $error");
     } else {
       $i->delete();
       throw new Exception("Error Cloning $id\n\tOUTPUT: $output\n\tERROR: $error");
     }
   } catch (Exception $exception) {
     $i->delete();
     throw new Exception("Exception Cloning $id\n\tEXCEPTION: $exception");
   }

   $comm = "cd $instance_path && zicode lesson:compose";
   Log::info("Composing instance $id\n\tCOMMOMAND:\n\t$comm");
   $process = new Process($comm);
   $process->setTimeout(3600);
   try {
     $process->mustRun();
     $output = $process->getOutput();
     $error = $process->getErrorOutput();
     if ($process->isSuccessful()) {
       Log::info("Composed instance with id $id\n\tOUTPUT: $output\n\tERROR: $error");
     } else {
       $i->delete();
       throw new Exception("Error composing instance with id $id\n\tOUTPUT: $output\n\tERROR: $error");
     }
   } catch (Exception $exception) {
     $i->delete();
     throw new Exception("Exception composing instance with id $id\n\tEXCEPTION: $exception");
   }

   $DOCKER_HOST = env('DOCKER_HOST');
   $comm = "export DOCKER_HOST=$DOCKER_HOST && cd $instance_path && zicode lesson:start $id $port";
   Log::info("Starting instance $id\n\tCOMMAND: $comm\n\tPORT: $port");
   $process = new Process($comm);
   $process->setTimeout(3600);
   try {
     $process->mustRun();
     $output = $process->getOutput();
     $error = $process->getErrorOutput();
     if ($process->isSuccessful()) {
       Log::info("Started instance with id $id, port: $port\n\tOUTPUT: $output\n\tERROR: $error");
     } else {
       $i->delete();
       throw new Exception("Error starting instance with id $id, port: $port\n\tOUTPUT: $output\n\tERROR: $error");
     }
   } catch (Exception $exception) {
     $i->delete();
     throw new Exception("Exception starting instance with id $id, port $port\n\tEXCEPTION: $exception");
   }
   return $instance;
 }
