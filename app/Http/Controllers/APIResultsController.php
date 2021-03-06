<?php namespace EID\Http\Controllers;

use EID\Http\Requests;
use EID\Http\Controllers\Controller;

use EID\VLAPI;
use EID\Mongo;

use EID\LiveData;
use Log;
use DateTime;
use DateInterval;
use LucaDegasperi\OAuth2Server\Facades\Authorizer;
use EID\User;

class APIResultsController extends Controller {

	public function __construct()
    {
        $this->mongo=Mongo::connect();
    }

	public function facility_list(){
		$facilities = $this->getFacilities();
		return view('api_results.facility_list', ['sect'=>'results', 'facilities'=>$facilities]);
	}

	public function facility_list_data(){
		$cols = ['facility', 'coordinator_name', 'coordinator_contact', 'coordinator_email'];
		$params = $this->get_params($cols);
		$params['hub'] = \Auth::user()->hub_id;
		$params['facility'] = \Auth::user()->facility_id;
		$facilities = $this->getFacilities1($params);

		$data = [];
		foreach ($facilities['data'] as $record) {
			extract($record);
			$facility_str = "<a href='/api/results/$pk'>$facility</a>";
			$data[] = [$facility_str, $coordinator_name, $coordinator_contact, $coordinator_email];
		}
		
		return [
			"draw" => \Request::get('draw'),
			"recordsTotal" => $facilities['recordsTotal'],
			"recordsFiltered" => $facilities['recordsFiltered'], 
			"data"=> $data
			];
	}

	public function results($facility_id){
		$facility = $this->mongo->api_facilities->findOne(['pk'=>(int)$facility_id]);
		$facility_name = isset($facility['facility'])?$facility['facility']:"";
		$tab = \Request::has('tab')?\Request::get('tab'):'pending';
		return view('api_results.results', compact('facility_id', 'facility_name','tab'));
	}

	public function results_data($facility_id){
		$cols = ['select','form_number', 'patient.art_number', 'patient.other_id', 'date_collected', 'date_received', 'result.resultsqc.released_at','resultsdispatch.dispatch_date', 'options'];
		$tab = \Request::has('tab')?\Request::get('tab'):'pending';
		//$cols[6] = $tab=='completed'?'resultsdispatch.dispatch_date':$cols[6];
		$params = $this->get_params($cols);
		$params['facility_id'] = $facility_id;

		$samples = $this->getSamples($params);

		$data = [];
		foreach ($samples['data'] as $sample) {
			extract($sample);
			$select_str = "<input type='checkbox' class='samples' name='samples[]' value='$_id'>";
			$url = "/api/result/$_id";
			$links = ['Print' => "javascript:windPop('$url')",'Download' => "$url?&pdf=1"];
			$released_at = $result['resultsqc']['released_at']?$result['resultsqc']['released_at']:$rejectedsamplesrelease['released_at'];
			$dispatch_date = $resultsdispatch['dispatch_date']? $resultsdispatch['dispatch_date']:"";
			$data[] = [
				$select_str, 
				$form_number, 
				$patient['art_number'], 
				$patient['other_id'], 
				\MyHTML::localiseDate($date_collected, 'd-M-Y'), 
				\MyHTML::localiseDate($date_received, 'd-M-Y'), 
				\MyHTML::localiseDate($released_at, 'd-M-Y'),
				\MyHTML::localiseDate($dispatch_date, 'd-M-Y'),
				//$tab=='completed'?\MyHTML::localiseDate($dispatch_date, 'd-M-Y'):\MyHTML::localiseDate($released_at, 'd-M-Y'),
				\MyHTML::dropdownLinks($links)];
		}

		return [
			"draw" => \Request::get('draw'),
			"recordsTotal" => $samples['recordsTotal'],
			"recordsFiltered" => $samples['recordsFiltered'], 
			"data"=> $data
			];
	}

	/*public function result($id=""){
		if(!empty($id)){
			$cond = ['_id'=>$this->_id($id)];
		}else{
			$samples = \Request::get("samples");
			if(count($samples)==0){
				return "please select at least one sample";
			}else{
				$objs = array_map(function($id){ return $this->_id($id); }, $samples);
				$cond = ['_id'=>['$in'=>$objs]];
			}
		}
		
		$vldbresult = $this->mongo->api_samples->find($cond);
		$tab = \Request::get('tab');
		if($tab=='pending'){
			$dispatch_type =  \Request::has('pdf')? 'D':'P';
			$log_update['resultsdispatch'] = [
				'dispatch_type'=>$dispatch_type, 
				'dispatch_date'=>date("Y-m-d").'T'.date("H:i:s"),
				'dispatched_by'=>\Auth::user()->username, 
				];
			$this->mongo->api_samples->update($cond,['$set'=>$log_update], ['multiple'=>true]);
		}		

		if(\Request::has('pdf')){
			$pdf = \PDF::loadView('api_results.result_slip', compact("vldbresult"));
			return $pdf->download('vl_results_'.\Request::get('facility').'.pdf');
		}
		return view('api_results.result_slip', compact('vldbresult'));
	}*/

	public function result($id=""){
		$vldbresult = [];
		if(!empty($id)){
			$samples = [$id];
		}elseif(\Request::has("form_numbers")){
			$forms = \Request::get('form_numbers');
			$forms_arr = explode(",", $forms);
			foreach($forms_arr as $form){
				$cond = ["form_number"=>"$form"];
				$res = $this->mongo->api_samples->findOne($cond);
				if($res) $vldbresult[] = $res;
				else $vldbresult[] = $form;
				
			}
			$pdf = \PDF::loadView('api_results.result_slip', compact("vldbresult"));
			return $pdf->download('vl_study_samples_'. date("YmdHis").'.pdf');
		}else{
			$samples = \Request::get("samples");
			if(count($samples)==0){
				return "please select at least one sample";
			}
		}		
		$tab = \Request::get('tab');
		$dispatch_type =  \Request::has('pdf')? 'D':'P';
		$log_update['resultsdispatch'] = [
			'dispatch_type'=>$dispatch_type, 
			'dispatch_date'=>date("Y-m-d").'T'.date("H:i:s"),
			'dispatched_by'=>\Auth::user()->username, 
			];
		foreach ($samples as $sample) {
			$cond = ["_id"=>$this->_id($sample)];
			$vldbresult[] = $this->mongo->api_samples->findOne($cond);
			if($tab=='pending')	$this->mongo->api_samples->update($cond,['$set'=>$log_update], ['multiple'=>false]);
		}

		if(\Request::has('pdf')){
			$pdf = \PDF::loadView('api_results.result_slip', compact("vldbresult"));
			return $pdf->download('vl_results_'.\Request::get('facility').'.pdf');
		}
		return view('api_results.result_slip', compact('vldbresult'));
	}

	public function search_result($txt){
    	$txt = str_replace(' ', '', $txt);
    	$cond = [];
    	$cond['$and'][] = ["created_at"=>['$gte'=>$this->mDate(env('QC_START_DATE'))]];
    	if(\Request::has('f')) $cond['$and'][] = ['facility.pk'=>(int)\Request::get('f')];
    	$mongo_search = new \MongoRegex("/$txt/i");
    	$cond['$and'][] = ['$or'=>[['result.resultsqc.released'=>true], ['rejectedsamplesrelease.released'=>true]]];
		$cond['$and'][] = ['$or'=>[['form_number' => $mongo_search], ['patient.art_number' => $mongo_search]]];
		$results = $this->mongo->api_samples->find($cond);
    	$ret = "<table class='table table-striped table-condensed table-bordered'>
    			<tr><th>Form Number</th><th>Art Number</th><th /></tr>";
    	foreach ($results AS $result){
    		$url = "/api/result/".$result['_id'];
    		$print_url = "<a href='javascript:windPop(\"$url\")'>print</a>";
    		$download_url = "<a href='$url?pdf=1'>download</a>";
    		$ret .= "<tr><td>$result[form_number]</td><td>".$result['patient']['art_number']."</td><td>$print_url | $download_url</td></tr>";	
    	}
    	return $ret."</table>";
    }

	private function _id($id){
		return new \MongoId($id);
	}

	private function get_params($cols){
    	$order = \Request::get('order');
    	$tab = \Request::get('tab');
    	$orderby = [$cols[0]=>1];		
		if(isset($order[0])){
			$col = $cols[$order[0]['column']];
			$dir = $order[0]['dir'];
			$orderby = $dir=='asc'?[$col=>1]:[$col=>-1];
		}

		$search = \Request::has('search')?\Request::get('search')['value']:"";
		$search = trim($search);
		$start = \Request::get('start');
		$length = \Request::get('length');
		$printed = $tab=='completed'?true:false;

		return compact('orderby','search', 'start', 'length', 'printed');
    }



	private function getFacilities1($params){
		$ret=[];
		extract($params);
		$cond=[];
		if(!empty($hub)) $cond['$and'][]=["hub"=>$hub];
		$ret['recordsTotal'] = $this->mongo->api_facilities->count($cond);
		if(!empty($search)) $cond['$and'][] = ['facility'=>new \MongoRegex("/$search/i")];
		$ret['data'] = $this->mongo->api_facilities->find($cond)->sort($orderby)->skip($start)->limit($length);
		$ret['recordsFiltered'] = $this->mongo->api_facilities->count($cond);
		return $ret;
	}

	private function getFacilities(){
		$ret = [];
		$cond = [];
		$hub = \Auth::user()->hub_id;
		$facility = \Auth::user()->facility_id;
		$cond['$and'][] = ["created_at"=>['$gte'=>$this->mDate(env('QC_START_DATE'))]];
		if(!empty($hub)){
			$cond['$and'][] = ["facility.hub.pk"=>(int)$hub];
		}elseif(!empty($facility)){
			$user_facilities = !empty(\Auth::user()->other_facilities)? unserialize(\Auth::user()->other_facilities):[];
			 array_push($user_facilities, $facility);
			 $cond['$and'][] = ["facility.pk"=>['$in'=>array_map(function($f){return (int)$f; }, $user_facilities)] ];
		}		

		$project = ["_id"=>0, "facility"=>1];
		#['$eq'=>['$result.resultsqc.released',true]];
		$pending_conds['$and'][] = ['$or'=>[['$eq'=>['$result.resultsqc.released',true]], ['$eq'=>['$rejectedsamplesrelease.released',true]]]];
		$pending_conds['$and'][] = ['$eq'=>['$resultsdispatch',null]];
		$project['num_pending'] = ['$cond'=>['if'=>$pending_conds, 'then'=>1, 'else'=>0]];
		$project['num_dispatched'] = ['$cond'=>['if'=>['$ne'=>['$resultsdispatch',null]], 'then'=>1, 'else'=>0]];
		$group =  ['_id'=> '$facility','num_pending' => ['$sum'=> '$num_pending'],'num_dispatched'=> ['$sum'=> '$num_dispatched'] ];
		
		$mresult = $this->mongo->api_samples->aggregate(
			['$match'=>$cond], 
			['$project'=>$project], 
			['$group'=>$group]
			// ['$sort'=>$orderby],
			// ['$skip'=>(int)$start],
			// ['$limit'=>(int)$length]
			);
		//$ret['data'] = $mresult['result'];
		return $mresult['result'];
	}

	private function getSamples($params){
		$ret=[];
		extract($params);
		$cond=[];
		$cond['$and'][] = ['$or'=>[['result.resultsqc.released'=>true], ['rejectedsamplesrelease.released'=>true]]];
		$cond['$and'][] = ["facility.pk"=>(int)$facility_id];
		$cond['$and'][] = ["created_at"=>['$gte'=>$this->mDate(env('QC_START_DATE'))]];
		if($printed==false){
			$cond['$and'][] = ['resultsdispatch'=>null];
		}else{
			$cond['$and'][] = ['resultsdispatch'=>['$ne'=>null]];
		} 
		$ret['recordsTotal'] = $this->mongo->api_samples->count($cond);
		if(!empty($search)){
			$mongo_search = new \MongoRegex("/$search/i");
			$cond['$and'][] = ['form_number' => $mongo_search];
		} 
		$ret['data'] = $this->mongo->api_samples->find($cond)->sort($orderby)->skip($start)->limit($length);
		$ret['recordsFiltered'] = $this->mongo->api_samples->count($cond);

		return $ret;
	}

	private function mDate($date_str){
		if(empty($date_str)) $date_str = date("Y-m-d");
		return new \MongoDate(strtotime($date_str));
	}

	public function getFacilitiesDataByAgeGroup($year_month,$gender,$from_age,$to_age){

		$mongo=Mongo::connect();
        
        $params = array(
                'year_month' => intval($year_month),
                
                'gender'=>$gender,
                'start_age' =>intval($from_age),
                'to_age'=>intval($to_age)//e.g. less than 15
            );
        
        $mongo_result_set = $this->getMonthlyData($params);
        $clean_result_set = $this->getCleanResultSet($mongo_result_set,$params);
        Log::info(".....api..dirty..");
        Log::info($mongo_result_set);
         Log::info(".....api. clean...");
        Log::info($clean_result_set);
        return $clean_result_set;
        
        
	}
	private function getFromYearMonth($year){
		$numeric_year = intval($year);
		$from_yearmonth = "$numeric_year"."01";
		$from_yearmonth = intval($from_yearmonth);

		return $from_yearmonth;
	}
	private function getToYearMonth($year){
		$numeric_year = intval($year);
		$to_yearmonth = "$numeric_year"."12";
		$to_yearmonth = intval($to_yearmonth);

		return $to_yearmonth;
	}
	private function getMonthlyData($params){
        
        $mongo=Mongo::connect();
        
            
            
          

            //match stage
            //--$match_array = array('year_month' => array('$gte'=>201501,'$lte'=>201512));
            $and_for_year_month=array('year_month' => array('$eq'=>$params['year_month']));
            $and_for_age=array('age' => array('$gte'=>$params['start_age'],'$lt'=>$params['to_age']));
            $and_for_gender=array('gender'=> array('$eq'=>$params['gender']));
            $match_array=array('$and' => array($and_for_year_month,$and_for_age,$and_for_gender));

          
            $eq_sample_result_validity = array('$eq' => array('$sample_result_validity','valid'));
            $cond_sample_result_validity = array($eq_sample_result_validity,1,0);


            $eq_number_suppressed = array('$eq' => array('$suppression_status','yes'));
            $cond_number_suppressed = array($eq_number_suppressed,1,0);

            $group_array = array(
                '_id' => array('facility_id'=>'$facility_id','year_month'=>'$year_month'), 
                'sample_result_validity' => array('$sum'=>  
                                array('$cond' => $cond_sample_result_validity )
                                ),
                
                'number_suppressed' => array('$sum'=>  
                                array('$cond' => $cond_number_suppressed )
                                )
                );

            //sorting
            $sort_array = array('facility_id' =>1 ,'year_month'=>-1);


        $result_set=$mongo->dashboard_new_backend->aggregate(['$match'=>$match_array],['$group'=>$group_array],
        	['$sort'=>$sort_array]);
        

        return $result_set['result'];
    }
    private function getAnnualData($params){
        
        $mongo=Mongo::connect();
        
            
            
          

            //match stage
            //--$match_array = array('year_month' => array('$gte'=>201501,'$lte'=>201512));
            $and_for_year_month=array('year_month' => array('$gte'=>$params['from_yearmonth'],'$lte'=>$params['to_yearmonth']));
            $and_for_age=array('age' => array('$gte'=>$params['start_age'],'$lt'=>$params['to_age']));
            $and_for_gender=array('gender'=> array('$eq'=>$params['gender']));
            $match_array=array('$and' => array($and_for_year_month,$and_for_age,$and_for_gender));

          
            $eq_sample_result_validity = array('$eq' => array('$sample_result_validity','valid'));
            $cond_sample_result_validity = array($eq_sample_result_validity,1,0);


            $eq_number_suppressed = array('$eq' => array('$suppression_status','yes'));
            $cond_number_suppressed = array($eq_number_suppressed,1,0);

            $group_array = array(
                '_id' => array('facility_id'=>'$facility_id','year_month'=>'$year_month'), 
                'sample_result_validity' => array('$sum'=>  
                                array('$cond' => $cond_sample_result_validity )
                                ),
                
                'number_suppressed' => array('$sum'=>  
                                array('$cond' => $cond_number_suppressed )
                                )
                );

            //sorting
            $sort_array = array('facility_id' =>1 ,'year_month'=>-1);


        $result_set=$mongo->dashboard_new_backend->aggregate(['$match'=>$match_array],['$group'=>$group_array],
        	['$sort'=>$sort_array]);
        

        return $result_set['result'];
    }
    
    private function getCleanResultSet($dataset,$params){
        $facilities = LiveData::getFacilitiesInAnArrayForm();

        $clean_result_set=array();
        //$clean_result_set['description']=$params;
           
            //headers
            $header['facilityID']='facilityID';
            $header['facility_name']='facility_name';
            $header['facility_dhis2_code']='dhis2_facility_id';
            $header['district_dhis2_code']='dhis2_district_id';
            //$header['sex']='sex';
            $header['year_month']='year_month';
            $header['number_of_valid_tests']='valid_tests';
            //$header['number_tested']='samples_tested';
            $header['number_suppressed']='suppressed';
            $header['suppression_rate']='suppression_rate';
        array_push($clean_result_set, $header);

     
        foreach ($dataset as $key => $record) {
            $facility_id=$record['_id']['facility_id'];
            $fields['facilityID']=$facility_id;
            
            
            try{
	            if(intval($facility_id) < 1 || intval($facility_id) == 3645 ||intval($facility_id) == 2317 || 
				intval($facility_id) == 1651 || intval($facility_id) == 461 || intval($facility_id) == 8363 || intval($facility_id) == 8362
				|| intval($facility_id) == 8366 || intval($facility_id) == 8365 || intval($facility_id) == 8364 )
	                continue;
		     }catch(Exception $e){
				continue;
		     }
            $fields['facility_name']=isset($facility) ? $facility['facility']: 'Null';
            $facility = isset($facilities[$facility_id])?$facilities[$facility_id]:null;
            $fields['facility_code'] = isset($facility) ? $facility['dhis2_uid']: 'Null';
            $fields['district_code']=isset($facility) ? $facility['district_uid']: 'Null';
           
           	$year_month=$record['_id']['year_month'];
           	$fields['year_month']=isset($year_month)?$year_month : 0;
     
            //$fields['sex']=$record['_id']['gender'];
            $number_of_valid_tests = isset($record['sample_result_validity'])?intval($record['sample_result_validity']) : 0;
            $fields['number_of_valid_tests']=$number_of_valid_tests;
            //$fields['number_tested']=isset($record['number_tested'])?$record['number_tested'] : 0;
            $number_suppressed = isset($record['number_suppressed'])? intval($record['number_suppressed']) : 0;
            $fields['number_suppressed']=$number_suppressed;

            $suppression_rate=0.0;
            if($number_suppressed>0){
            	$suppression_rate = round(($number_suppressed /$number_of_valid_tests)*100);
            }
            $fields['suppression_rate'] = $suppression_rate;
                        
            array_push($clean_result_set, $fields);
        }
        
        return $clean_result_set;
    }

    public function getDistricts(){
    	$sql = "SELECT id,district,dhis2_uid FROM backend_districts";

    	return \DB::connection('direct_db')->select($sql); 
    }

    public function getDistrictsNumbers(){
    	
        $and_for_year_month=array('year_month' => array('$gte'=>201801,'$lt'=>201812));
        
        //$match_array=array('$and' => array($and_for_year_month,$and_for_age,$and_for_gender));
		$match_stage['$match']=$and_for_year_month;

		$group_stage = array(
			
			'$group' => array(
				'_id' => '$district_id', 
				'samples_received' => array('$sum' => 1 ),
				'valid_results' => array('$sum' => array('$cond'=>array(array('$eq' => array('$sample_result_validity','valid')),1,0))),
				'dbs_samples' => array('$sum' => array('$cond'=>array(array('$eq' => array('$sample_type_id',1)),1,0))),
				'total_results' => array('$sum' => array('$cond'=>array(array('$eq' => array('$tested','yes')),1,0))),
				'suppressed' => array('$sum' => array('$cond'=>array(array('$eq' => array('$suppression_status','yes')),1,0))),
				'rejected_samples' => array(
					'$sum' => array(
						'$cond'=>array(
							array('$or' => array(
									'$eq' => array('$rejection_category','eligibility'),
									'$eq' => array('$rejection_category','data_quality'),
									'$eq' => array('$rejection_category','sample_quality')
								)
								),1,0)
						)
					),
				'unique_patient_count' =>array(
						'$addToSet'=>'$patient_unique_id'
					)
			 ));
		
		$project_stage['$project']=array(
			'samples_received'=>1,'valid_results'=>1,'dbs_samples'=>1,'total_results'=>1,'suppressed' => 1,'rejected_samples'=>1,'sample_quality_rejections'=>1, 'incomplete_form_rejections'=>1,'eligibility_rejections'=>1,'patients_received'=>array('$size'=>'$unique_patient_count')
			);
		
		$res=$this->mongo->dashboard_new_backend->aggregate($match_stage,$group_stage,$project_stage);
		
		
		return isset($res['result'])?$res['result']:[];
	}

	public function getDistrictsNumberByMonth($year_month){
    	$int_year_month = intval($year_month);
    	Log::info($year_month);
        $and_for_year_month=array('year_month' => array('$eq'=>$int_year_month ));
        
        //$match_array=array('$and' => array($and_for_year_month,$and_for_age,$and_for_gender));
		$match_stage['$match']=$and_for_year_month;

		$group_stage = array(
			
			'$group' => array(
				'_id' => '$district_id', 
				'samples_received' => array('$sum' => 1 ),
				'valid_results' => array('$sum' => array('$cond'=>array(array('$eq' => array('$sample_result_validity','valid')),1,0))),
				'dbs_samples' => array('$sum' => array('$cond'=>array(array('$eq' => array('$sample_type_id',1)),1,0))),
				'total_results' => array('$sum' => array('$cond'=>array(array('$eq' => array('$tested','yes')),1,0))),
				'suppressed' => array('$sum' => array('$cond'=>array(array('$eq' => array('$suppression_status','yes')),1,0))),
				'rejected_samples' => array(
					'$sum' => array(
						'$cond'=>array(
							array('$or' => array(
									'$eq' => array('$rejection_category','eligibility'),
									'$eq' => array('$rejection_category','data_quality'),
									'$eq' => array('$rejection_category','sample_quality')
								)
								),1,0)
						)
					),
				'unique_patient_count' =>array(
						'$addToSet'=>'$patient_unique_id'
					)
			 ));
		
		$project_stage['$project']=array(
			'samples_received'=>1,'valid_results'=>1,'dbs_samples'=>1,'total_results'=>1,'suppressed' => 1,'rejected_samples'=>1,'sample_quality_rejections'=>1, 'incomplete_form_rejections'=>1,'eligibility_rejections'=>1,'patients_received'=>array('$size'=>'$unique_patient_count')
			);

		$res=$this->mongo->dashboard_new_backend->aggregate($match_stage,$group_stage,$project_stage);
		
		$json_array_test = json_encode(isset($res['result'])?$res['result']:[]);
		$this->exportToCsv($json_array_test,$year_month);
		return isset($res['result'])?$res['result']:[];
	}
    /*
		SELECT  s.id, s.patient_unique_id, s.facility_id, f.facility, s.vl_sample_id, 
s.date_collected, s.date_received,s.created_at, s.sample_type, s.clinician_id, c.cname, 
p.art_number, p.gender, p.dob, drequest.patient_on_rifampicin  
 FROM vl_samples s left join vl_patients p on p.id = s.patient_id  
inner join backend_facilities f on f.id=s.facility_id  
inner join vl_clinicians c on c.id=s.clinician_id  
inner join vl_drug_resistance_requests drequest on drequest.sample_id = s.id 
where YEAR(s.created_at)=2018 and drequest.body_weight is not NULL or drequest.patient_on_rifampicin is not NULL

    */

    public function getHivDrugResistanceTests($year,$month){
    	$_year = intval($year);
    	$_month=intval($month);
    	
    	$sql="SELECT s.id as sample_id,s.patient_unique_id,s.facility_id,f.facility,s.vl_sample_id,s.date_collected,s.date_received,
    	    s.sample_type,s.clinician_id,c.cname,p.art_number,p.gender,p.dob,drr.id as drr_id
    	FROM vl_production_v2.vl_samples s left join vl_patients p on p.id = s.patient_id 
    	inner join backend_facilities f on f.id=s.facility_id 
    	inner join vl_clinicians c on c.id=s.clinician_id 
    	inner join vl_drug_resistance_requests drr on drr.sample_id=s.id 
    	where YEAR(s.created_at)=$_year and MONTH(s.created_at)=$_month and drr.body_weight is not NULL";

    	return \DB::connection('direct_db')->select($sql); 
    	
    }

    public function receiveHivDrugResistanceTests(){
    	Log::info("Data received from IP");
    	
    	$return_message="";
    	$request = \Request();
    	$array_of_sample_results = $request->request->all();
    	Log::info("....post ....");
    	Log::info($array_of_sample_results);
    	Log::info("....post .1..");
    	
    	foreach ($array_of_sample_results as $key => $value) {
    		
    		Log::info("....key ....");
    		Log::info($key);
    		Log::info("....value ....");
    		Log::info($value);
    		

    		
    		$drr_uploaded_by_id=1;
    		
    		$api_user=User::find(Authorizer::getResourceOwnerId());
    	    $api_username = $api_user->username;//$value['api_username'];
    	    
    		$drr_id=intval($value['drr_id']);

    		$sample_id=$value['sample_id'];
    		$vl_sample_id=$value['vl_sample_id'];
    		$cphl_patient_unique_id=$value['cphl_patient_unique_id'];

    		$accession_number=$value['accession_number'];
    		$entity_patient_id=$value['entity_patient_id'];
    		
    		$analyst=$value['analyst'];
    		$no_charge_reason=$value['no_charge_reason'];
    		$study_name=$value['study_name'];
    		$study_code=$value['study_code'];

    		$test_date=$value['test_date'];
    		$result_date=$value['result_date'];
    		$result_string=serialize($value['result_string']) ;//send this to the filesystem
    		$form_number=$value['form_number'];

    		//$this->saveHivDrugResistanceResultToFileSystem($result_string,$vl_sample_id);

    		
    		//insert into database
    		$sql="INSERT INTO vl_drug_resistance_results 
					(
					results_file,upload_date,drr_uploaded_by_id,drug_resistance_request_id,
					accession_number,cphl_patient_unique_id,vl_sample_id,entity_patient_id,
					analyst,no_charge_reason,study_name,study_code,
					test_date,result_date,result_string,form_number,api_username 
					) 
					VALUES
					('api_upload',NOW(),$drr_uploaded_by_id,$drr_id,
					  '$accession_number','$cphl_patient_unique_id','$vl_sample_id','$entity_patient_id',
					  '$analyst','$no_charge_reason','$study_name','$study_code',
					  '$test_date','$result_date','$result_string',$form_number,'$api_username'
					  )";
    		$return_message="data arrived in one piece.";
    		
    		try {
    			Log::info("....Start saving ....");
    			\DB::connection('direct_db')->insert($sql);
    			Log::info("....saved successfully ....");
    			$return_message="successfully received $accession_number";
    		} catch (Exception $e) {
    			$return_message = "Ensure all fields are valid";//$e->getMessage();
    			Log::error("Hiv Drug Resistance accession number: $accession_number");
    			Log::error($e->getMessage());
    		}
		   

    	}
    	return $return_message;

    }

    private function saveHivDrugResistanceResultToFileSystem($result_array,$vl_sample_id){

    	//if directory is absent, create it.
    	$new_directory='hiv_drug_resistance/';
        $directory_path="./docs/".$new_directory;
        if (!is_dir($directory_path)) {
            mkdir($directory_path, 0777, true);
        }

        

    	//save file there.
        $sample_id=str_replace("/", "_", $vl_sample_id);
        $file_url=$directory_path.$sample_id.".json";
        //$sample_result_file = fopen($file_url, "w") or die("Unable to open file!");
        //fwrite($sample_result_file, $result_string);
        //fclose($sample_result_file);

        //Encode the array into a JSON string.
        $encodedString = json_encode($result_array);
 
        //Save the JSON string to a text file.
        file_put_contents($file_url, $encodedString);
 
        //Retrieve the data from our text file.
        //$fileContents = file_get_contents('json_array.txt');
    }

    private function exportToCsv($data_in_json,$year_month){

		//Decode the JSON and convert it into an associative array.
		$jsonDecoded = json_decode($data_in_json, true);
		 
		//Give our CSV file a name.
		$csvFileName = '/tmp/vl/'.$year_month.'.csv';
		 
		//Open file pointer.
		$fp = fopen($csvFileName, 'w');
		 
		 //header
		 $header_row=['district_id','samples_received','valid_results','dbs_samples','total_results','suppressed','rejected_samples','patients_received'];
		 fputcsv($fp, $header_row);
		//Loop through the associative array.
		foreach($jsonDecoded as $row){
		    //Write the row to the CSV file.
		    fputcsv($fp, $row);
		}
		 
		//Finally, close the file pointer.
		fclose($fp);
    }



}