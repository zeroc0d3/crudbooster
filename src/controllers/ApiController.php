<?php namespace crocodicstudio\crudbooster\controllers;

use crocodicstudio\crudbooster\controllers\Controller;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ApiController extends Controller {
			
	var $method_type;
	var $permalink;
	var $hook_api_status;
	var $hook_api_message;	
	var $last_id_tmp       = array();
	

	public function hook_before(&$postdata) {

	}
	public function hook_after($postdata,&$result) {
		
	}

	public function hook_query_list(&$data) {

	}

	public function hook_query(&$query) {

	}

	public function hook_query_detail(&$data) {

	}

	public function hook_api_status($boolean) {
		$this->hook_api_status = $boolean;
	}
	public function hook_api_message($message) {
		$this->hook_api_message = $message;
	}

	public function execute_api() {						

		// DB::enableQueryLog();

		$posts        = Request::all();
		$posts_keys   = array_keys($posts);
		$posts_values = array_values($posts);

		$this->hook_before($posts);

		$row_api = DB::table('cms_apicustom')->where('permalink',$this->permalink)->first();	
		$action_type              = $row_api->aksi;
		$table                    = $row_api->tabel;
		$limit                    = ($posts['limit'])?:20;
		$offset                   = ($posts['offset'])?:0;
		$orderby                  = ($posts['orderby'])?:$table.'.id,desc';		
		$uploads_format_candidate = explode(',',config("crudbooster.UPLOAD_TYPES"));	
		$uploads_candidate        = explode(',',config('crudbooster.IMAGE_FIELDS_CANDIDATE'));
		$password_candidate       = explode(',',config('crudbooster.PASSWORD_FIELDS_CANDIDATE'));	
		$asset					  = asset('/');				
		
		unset($posts['limit']);
		unset($posts['offset']);
		unset($posts['orderby']);	

		/* 
		| ----------------------------------------------
		| Check the row is exists or not
		| ----------------------------------------------
		|
		*/
		if(!$row_api) {
			$result['api_status']  = 0;
			$result['api_message'] = 'Sorry this API is no longer available, maybe has changed by admin, or please make sure api url is correct.';
			goto show;
		}

		@$parameters = unserialize($row_api->parameters);
		@$responses = unserialize($row_api->responses);		

		/* 
		| ----------------------------------------------
		| User Data Validation
		| ----------------------------------------------
		|
		*/
		if($parameters) {
			$type_except = ['password','ref','base64_file'];
			$input_validator = array();
			$data_validation = array();
			foreach($parameters as $param) {
				$name     = $param['name'];
				$type     = $param['type'];
				$value    = $posts[$name];
				
				$required = $param['required'];
				$config   = $param['config'];
				$used     = $param['used'];
				$format_validation = array();

				if($used == 0) continue;

				if($required) {
					$format_validation[] = 'required';
				}
				
				if($type == 'exists') {
					$format_validation[] = 'exists:'.$config;
				}elseif ($type == 'unique') {
					$format_validation[] = 'unique:'.$config;
				}elseif ($type == 'date_format') {
					$format_validation[] = 'date_format:'.$config;						
				}elseif ($type == 'digits_between') {
					$format_validation[] = 'digits_between:'.$config;						
				}elseif ($type == 'in') {
					$format_validation[] = 'in:'.$config;						
				}elseif ($type == 'mimes') {
					$format_validation[] = 'mimes:'.$config;						
				}elseif ($type == 'min') {
					$format_validation[] = 'min:'.$config;						
				}elseif ($type == 'max') {
					$format_validation[] = 'max:'.$config;						
				}elseif ($type == 'not_in') {
					$format_validation[] = 'not_in:'.$config;						
				}else{
					if(!in_array($type, $type_except)) {
						$format_validation[] = $type;
					}						
				}		

				if($name == 'id') {
					$format_validation[] = 'exists:'.$table.',id';
				}						
				
				$input_validator[$name] = $value;
				$data_validation[$name] = implode('|',$format_validation);
			}


			$validator = Validator::make($input_validator,$data_validation);		    
		    if ($validator->fails()) 
		    {
		        $message = $validator->errors()->all(); 
		        $message = implode(', ',$message);
		        $result['api_status'] = 0;
		        $result['api_message'] = $message;
		        goto show;
		    }

		    			
		}


		/* 
		| ----------------------------------------------
		| Method Type validation
		| ----------------------------------------------
		|
		*/
		if($row_api->method_type) {
			$method_type = $row_api->method_type;
			if($method_type) {
				if(!Request::isMethod($method_type)) {
					$result['api_status'] = 0;
					$result['api_message'] = "The request method is not allowed !";
					goto show;
				}
			}			
		}

		$responses_fields = array();
		foreach($responses as $r) {
			$responses_fields[] = $r['name'];
		}
		

		if($action_type == 'list' || $action_type == 'detail' || $action_type == 'delete') {
			$name_tmp = array();
			$data = DB::table($table);	 				
			$data->skip($offset);
			$data->take($limit);								
			foreach($responses as $resp) {	
				$name = $resp['name'];
				$type = $resp['type'];
				$subquery = $resp['subquery'];
				$used = intval($resp['used']);

				if($used == 0 && !is_foreign_key($name)) continue;

				if(in_array($name, $name_tmp)) continue;

				if($name == 'ref_id') continue;

				if($subquery) {
					$data->addSelect(DB::raw(
						'('.$subquery.') as '.$name
						));
					$name_tmp[] = $name;
					continue;
				}

				if($used) {
					$data->addSelect($table.'.'.$name);	
				}				

				$name_tmp[] = $name;
				if(is_foreign_key($name)) {
					$jointable = is_foreign_key($name);
					$jointable_field = DB::getSchemaBuilder()->getColumnListing($jointable);
					$data->leftjoin($jointable,$jointable.'.id','=',$table.'.'.$name);
					foreach($jointable_field as $jf) {							
						$jf_alias = $jointable.'_'.$jf;
						if(in_array($jf_alias, $responses_fields)) {

							$data->addselect($jointable.'.'.$jf.' as '.$jf_alias);							

							$name_tmp[] = $jf_alias;
						}							
					}
				}
			} //End Responses

			foreach($parameters as $param) {
				$type = $param['type'];
				$name = $param['name'];
				if($type == 'password') {
					$data->addselect($table.'.'.$name);
				}
			}

			if(\Schema::hasColumn($table,$table.'.deleted_at')) {
				$data->where('deleted_at',NULL);
			}

			if($posts['search_in'] && $posts['search_value']) {
				$search_in = explode(',',$posts['search_in']);
				$search_value = $posts['search_value'];
				$data->where(function($w) use ($search_in,$search_value) {
					foreach($search_in as $k=>$field) {
						if($k==0) $w->where($field,"like","%$search_value%");
						else $w->orWhere($field,"like","%$search_value%");
					}
				});
			}

			
			$data->where(function($w) use ($parameters,$posts,$table,$type_except) {								
				foreach($parameters as $param) {
					$name     = $param['name'];
					$type     = $param['type'];
					$value    = $posts[$name];
					$used     = $param['used'];
					$required = $param['required'];					

					if(in_array($type, $type_except)) {
						continue;
					}

					if($required == '1') {						
						if(\Schema::hasColumn($table,$name)) {
							$w->where($table.'.'.$name,$value);
						}else{
							$w->having($name,'=',$value);
						}
					}else{
						if($used) {
							if($value) {
								if(\Schema::hasColumn($table,$name)) {
									$w->where($table.'.'.$name,$value);
								}else{
									$w->having($name,'=',$value);
								}
							}						
						}
					}									
				}
			});
									
			//IF SQL WHERE IS NOT NULL
			if($row_api->sql_where) {
				$data->whereraw($row_api->sql_where);
			}

			$this->hook_query_list($data);
			$this->hook_query($data);

			if($action_type == 'list') {
				if($orderby) {
					$orderby_raw = explode(',',$orderby);
					$orderby_col = $orderby_raw[0];
					$orderby_val = $orderby_raw[1];
				}else{
					$orderby_col = $table.'.id';
					$orderby_val = 'desc';
				}
				
				$rows = $data->orderby($orderby_col,$orderby_val)->get();																						

				if($rows) {

					foreach($rows as &$row) {
						foreach($row as $k=>$v) {
							$ext = \File::extension($v);
							if(in_array($ext, $uploads_format_candidate)) {
								$row->$k = asset($v);
							}

							if(!in_array($k,$responses_fields)) {
								unset($row[$k]);
							}
						}						
					}

					$result['api_status']  = 1;
					$result['api_message'] = 'success';				
					$result['data']        = $rows;
				}else{
					$result['api_status']  = 0;
					$result['api_message'] = 'There is no data found !';				
					$result['data']        = array();
				}
			}elseif ($action_type == 'detail') {
							
				$rows = $data->first();

				if($rows) {					

					foreach($parameters as $param) {
						$name     = $param['name'];
						$type     = $param['type'];
						$value    = $posts[$name];
						$used     = $param['used'];
						$required = $param['required'];

						if($required) {
							if($type == 'password') {
								if(!Hash::check($value,$rows->{$name})) {
									$result['api_status'] = 0;
									$result['api_message'] = 'Your password is wrong !';					
									goto show;
								}
							}
						}else{
							if($used) {
								if($value) {
									if(!Hash::check($value,$row->{$name})) {
										$result['api_status'] = 0;
										$result['api_message'] = 'Your password is wrong !';
										goto show;
									}
								}
							}
						}
					}


					foreach($rows as $k=>$v) {
						$ext = \File::extension($v);
						if(in_array($ext, $uploads_format_candidate)) {
							$rows->$k = asset($v);
						}

						if(!in_array($k,$responses_fields)) {
							unset($row[$k]);
						}
					}

					$result['api_status']  = 1;
					$result['api_message'] = 'success';
					$rows                  = (array) $rows;
					$result                = array_merge($result,$rows);
				}else{
					$result['api_status']  = 0;
					$result['api_message'] = 'There is no data found !';					
				}
			}elseif($action_type == 'delete') {
				
				if(\Schema::hasColumn($table,'deleted_at')) {
					$delete = $data->update(['deleted_at'=>date('Y-m-d H:i:s')]);
				}else{
					$delete = $data->delete();
				}

				$result['api_status'] = ($delete)?1:0;
				$result['api_message'] = ($delete)?"The data has been deleted successfully !":"Oops, Failed to delete data !";

			}

		}elseif ($action_type == 'save_add' || $action_type == 'save_edit') {
			
		    $row_assign = array();
		    foreach($input_validator as $k=>$v) {
		    	if(\Schema::hasColumn($table,$k)) {
		    		$row_assign[$k] = $v;
		    	}
		    }

		    if($action_type == 'save_add') {
		    	if(\Schema::hasColumn($table,$table.'.created_at')) {
		    		$row_assign['created_at'] = date('Y-m-d H:i:s');
		    	}
		    } 

		    if($action_type == 'save_edit') {
		    	if(\Schema::hasColumn($table,$table.'.updated_at')) {
		    		$row_assign['updated_at'] = date('Y-m-d H:i:s');
		    	}
		    }

		    $row_assign_keys = array_keys($row_assign);

		    foreach($parameters as $param) {
		    	$name = $param['name'];
		    	$value = $posts[$name];
		    	$config = $param['config'];
		    	$type = $param['type'];
		    	$required = $param['required'];
		    	$used = $param['used'];

		    	if(!in_array($name, $row_assign_keys)) {
					continue;
				}	

		    	if($type == 'file' || $type == 'image') {
		    		if (Request::hasFile($name))
					{			
						$file = Request::file($name);					
						$ext  = $file->getClientOriginalExtension();

						//Create Directory Monthly 
						Storage::makeDirectory(date('Y-m'));

						//Move file to storage
						$filename = md5(str_random(5)).'.'.$ext;
						if($file->move(storage_path('app'.DIRECTORY_SEPARATOR.date('Y-m')),$filename)) {						
							$v = 'uploads/'.date('Y-m').'/'.$filename;
							$row_assign[$name] = $v;
						}					  
					}	
		    	}elseif ($type == 'base64_file') {
		    		$filedata = base64_decode($value);
					$f = finfo_open();
					$mime_type = finfo_buffer($f, $filedata, FILEINFO_MIME_TYPE);
					@$mime_type = explode('/',$mime_type);
					@$mime_type = $mime_type[1];
					if($mime_type) {
						if(in_array($mime_type, $uploads_format_candidate)) {
							Storage::makeDirectory(date('Y-m'));
							$filename = md5(str_random(5)).'.'.$mime_type;
							if(file_put_contents(storage_path('app'.DIRECTORY_SEPARATOR.date('Y-m')).'/'.$filename, $filedata)) {
								$v = 'uploads/'.date('Y-m').'/'.$filename;
								$row_assign[$name] = $v;
							}
						}
					}
		    	}elseif ($type == 'password') {
		    		$row_assign[$name] = Hash::make($value);
		    	}
		    	
		    }


		    if($action_type == 'save_add') {
		    	
		    	$lastId = DB::table($table)->insertGetId($row_assign);
		    	$result['api_status']  = ($lastId)?1:0;
				$result['api_message'] = ($lastId)?'The data has been added successfully':'Failed to add data !';
				$result['id']          = $lastId;
		    }else{

		    	$update = DB::table($table);

			    $update->where($table.'.id',$row_assign['id']);

			    if($row_api->sql_where) {
			    	$update->whereraw($row_api->sql_where);
			    }			    

			    $this->hook_query_list($update);
			    $this->hook_query($update);

			    $update = $update->update($row_assign);
				$result['api_status']  = ($update)?1:0;
				$result['api_message'] = ($update)?'The data has been saved successfully':'Oops, Failed to save data !';

		    }

		    // Update The Child Table
		    foreach($parameters as $param) {
		    	$name = $param['name'];
		    	$value = $posts[$name];
		    	$config = $param['config'];
		    	$type = $param['type'];
		    	if($type == 'ref') {
		    		if(\Schema::hasColumn($config,'id_'.$table)) {
		    			DB::table($config)->where($name,$value)->update(['id_'.$table=>$lastId]);
		    		}elseif (\Schema::hasColumn($config,$table.'_id')) {
		    			DB::table($config)->where($name,$value)->update([$table.'_id'=>$lastId]);
		    		}			    		
		    	}
		    }
		}


		
		show:
		$result['api_status']  = $this->hook_api_status?:$result['api_status'];
		$result['api_message'] = $this->hook_api_message?:$result['api_message'];
		// $result['database_query'] = DB::getQueryLog();

		$this->hook_after($posts,$result);

		return response()->json($result);
	}
	
}




