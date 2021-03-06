<?php
/**
 * Sharif Judge online judge
 * @file Submit.php
 * @author Mohammad Javad Naderi <mjnaderi@gmail.com>
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Submit extends CI_Controller
{

	private $data; //data sent to view
	private $assignment_root;
	private $problems;
	private $problem;//submitted problem id
	private $filetype; //type of submitted file
	private $ext; //uploaded file extension
	private $file_name; //uploaded file name without extension
	private $coefficient;

	private $language_to_ext = array(
		 'c' => 'c'
		, 'cpp' => 'cpp'
		, 'py2' => 'py'
		, 'py3' => 'py'
		, 'java' => 'java'
		, 'zip' => 'zip'
		, 'pdf' => 'pdf'
	);

	// ------------------------------------------------------------------------


	public function __construct()
	{
		parent::__construct();
		if ( ! $this->session->userdata('logged_in')) // if not logged in
			redirect('login');
		$this->load->library('upload')->model('queue_model');
		$this->assignment_root = $this->settings_model->get_setting('assignments_root');
		$this->problems = $this->assignment_model->all_problems($this->user->selected_assignment['id']);

		$extra_time = $this->user->selected_assignment['extra_time'];
		$delay = shj_now()-strtotime($this->user->selected_assignment['finish_time']);;
		ob_start();
		if ( eval($this->user->selected_assignment['late_rule']) === FALSE )
			$coefficient = "error";
		if (!isset($coefficient))
			$coefficient = "error";
		ob_end_clean();
		$this->coefficient = $coefficient;

	}


	// ------------------------------------------------------------------------


	public function _language_to_type($language)
	{
		$language = strtolower ($language);
		switch ($language) {
			case 'c': return 'c';
			case 'c++': return 'cpp';
			case 'python 2': return 'py2';
			case 'python 3': return 'py3';
			case 'java': return 'java';
			case 'zip': return 'zip';
			case 'pdf': return 'pdf';
			default: return FALSE;
		}
	}


	// ------------------------------------------------------------------------


	public function _match($type, $extension)
	{
		switch ($type) {
			case 'c': return ($extension==='c'?TRUE:FALSE);
			case 'cpp': return ($extension==='cpp'?TRUE:FALSE);
			case 'py2': return ($extension==='py'?TRUE:FALSE);
			case 'py3': return ($extension==='py'?TRUE:FALSE);
			case 'java': return ($extension==='java'?TRUE:FALSE);
			case 'zip': return ($extension==='zip'?TRUE:FALSE);
			case 'pdf': return ($extension==='pdf'?TRUE:FALSE);
		}
	}


	// ------------------------------------------------------------------------


	public function _check_language($str)
	{
		if ($str=='0')
			return FALSE;
		if (in_array( strtolower($str),array('c', 'c++', 'python 2', 'python 3', 'java', 'zip', 'pdf')))
			return TRUE;
		return FALSE;
	}

	public function template(){
		// Find pdf file

		if ( ! $this->input->is_ajax_request() )
			show_404();

		$this->form_validation->set_rules('assignment','assignment','integer|greater_than[0]');
		$this->form_validation->set_rules('problem','problem','integer|greater_than[0]');

		if($this->form_validation->run())
		{
			$assignment_id = $this->input->post('assignment');
			$problem_id = $this->input->post('problem');

			$pattern1 = rtrim($this->settings_model->get_setting('assignments_root'),'/')
						."/assignment_{$assignment_id}/p{$problem_id}/template.public.cpp";

			$template_file = glob($pattern1);
			if ( ! $template_file ){
				$pattern = rtrim($this->settings_model->get_setting('assignments_root'),'/')
							."/assignment_{$assignment_id}/p{$problem_id}/template.cpp";

				$template_file = glob($pattern);

			}

			if(!$template_file){
				$result = array('banned' => '', 'before'  => '', 'after' => '');
			} else {
				$filename = shj_basename($template_file[0]);
				$template = file_get_contents($template_file[0]);

				preg_match("/(\/\*###Begin banned.*\n)((.*\n)*)(###End banned keyword\*\/)/"
					, $template, $matches
				);


				$set_or_empty = function($arr, $key){
					//print_r($arr[$key]);

					if(isset($arr[$key])) return $arr[$key];
					return "";
				};

				$banned = $set_or_empty($matches, 2);

				preg_match("/(###End banned keyword\*\/\n)((.*\n)*)\/\/###INSERT CODE HERE -\n((.*\n)*)/"
					, $template, $matches
				);
				//print_r($matches);
				$before = $set_or_empty($matches, 2);
				$after = $set_or_empty($matches, 4);

				$result = array('banned' => $banned, 'before'  => $before, 'after' => $after);
			}

			$this->output->set_content_type('application/json')
				->set_output(json_encode($result));
		}
		else
			exit('Are you trying to see other users\' codes? :)');
	}

	// ------------------------------------------------------------------------


	public function index()
	{
		$this->form_validation->set_rules('problem', 'problem', 'required|integer|greater_than[0]', array('greater_than' => 'Select a %s.'));
		$this->form_validation->set_rules('language', 'language', 'required|callback__check_language', array('_check_language' => 'Select a valid %s.'));

		if ($this->form_validation->run())
		{
			if ($this->_upload())
				redirect('submissions/all');
			else
				show_error('Error Uploading File: '.$this->upload->display_errors());
		}

		$this->data = array(
			'all_assignments' => $this->assignment_model->all_assignments(),
			'problems' => $this->problems,
			'in_queue' => FALSE,
			'coefficient' => $this->coefficient,
			'upload_state' => '',
			'problems_js' => '',
			'error' => '',
		);
		foreach ($this->problems as $problem)
		{
			$languages = explode(',', $problem['allowed_languages']);
			$items='';
			foreach ($languages as $language)
			{
				$items = $items."'".trim($language)."',";
			}
			$items = substr($items,0,strlen($items)-1);
			$this->data['problems_js'] .= "shj.p[{$problem['id']}]=[{$items}]; ";
		}

		if ($this->user->selected_assignment['id'] == 0)
			$this->data['error']='Please select an assignment first.';
		else {
			$this->data['error'] = $this->assignment_model->can_submit($this->user->selected_assignment)['error_message'];
		}


		$this->data['from'] = "";
		$this->load->library('user_agent');
	    $a = $this->agent->referrer();

		if (preg_match('/\/problems\/\d+\/(\d+)$/', $a, $pno)) $this->data['from'] = $pno[1];

		$this->twig->display('pages/submit.twig', $this->data);

	}


	// ------------------------------------------------------------------------


	/**
	 * Saves submitted code and adds it to queue for judging
	 */
	private function _upload()
	{
		$now = shj_now();
		foreach($this->problems as $item)
			if ($item['id'] == $this->input->post('problem'))
			{
				$this->problem = $item;
				break;
			}
		$this->filetype = $this->_language_to_type(strtolower(trim($this->input->post('language'))));

		if ( $this->queue_model->in_queue($this->user->username,$this->user->selected_assignment['id'], $this->problem['id']) )
			show_error('You have already submitted for this problem. Your last submission is still in queue.');
		if ($this->user->level==0 && !$this->user->selected_assignment['open'])
			show_error('Selected assignment has been closed.');
		if ($now < strtotime($this->user->selected_assignment['start_time']) && $this->user->level == 0)
			show_error('Selected assignment has not started.');
		if (strtotime($this->user->selected_assignment['start_time']) < strtotime($this->user->selected_assignment['finish_time'])
			&& $now > strtotime($this->user->selected_assignment['finish_time'])+$this->user->selected_assignment['extra_time'])
			show_error('Selected assignment has finished.');
		if ( ! $this->assignment_model->is_participant($this->user->selected_assignment['participants'],$this->user->username) )
			show_error('You are not registered for submitting.');
		$filetypes = explode(",",$this->problem['allowed_languages']);
		foreach ($filetypes as &$filetype)
		{
			$filetype = $this->_language_to_type(strtolower(trim($filetype)));
		}

		if ( ! in_array($this->filetype, $filetypes))
			show_error('This file type is not allowed for this problem.');

		$user_dir = rtrim($this->assignment_root, '/').'/assignment_'.$this->user->selected_assignment['id'].'/p'.$this->problem['id'].'/'.$this->user->username;
		if ( ! file_exists($user_dir))
			mkdir($user_dir, 0700);

		$a = $this->input->post('code');
		if ($a != NULL){
			$this->ext = $this->language_to_ext[$this->filetype];

			$file_name = "solution";
			file_put_contents("$user_dir/$file_name-"
								.($this->user->selected_assignment['total_submits']+1)
								. "." . $this->ext, $a);

			$this->load->model('submit_model');

			$submit_info = array(
				'submit_id' => $this->assignment_model->increase_total_submits($this->user->selected_assignment['id']),
				'username' => $this->user->username,
				'assignment' => $this->user->selected_assignment['id'],
				'problem' => $this->problem['id'],
				'file_name' => "$file_name-"
								.($this->user->selected_assignment['total_submits']+1),
				'main_file_name' => "$file_name",
				'file_type' => $this->filetype,
				'coefficient' => $this->coefficient,
				'pre_score' => 0,
				'time' => shj_now_str(),
			);

			if ($this->problem['is_upload_only'] == 0)
			{
				$this->queue_model->add_to_queue($submit_info);
				process_the_queue();
			}
			else
			{
				$this->submit_model->add_upload_only($submit_info);
			}

			return TRUE;
		}

		//var_dump($_FILES); die();

		if (!isset($_FILES['userfile']) or $_FILES['userfile']['error'] == 4)
			show_error('No file chosen.');



		$this->ext = substr(strrchr($_FILES['userfile']['name'],'.'),1); // uploaded file extension
		$this->file_name = basename($_FILES['userfile']['name'], ".{$this->ext}"); // uploaded file name without extension
		if ( ! $this->_match($this->filetype, $this->ext) )
			show_error('This file type does not match your selected language.');
		if ( ! preg_match('/^[a-zA-Z0-9_\-()]+$/', $this->file_name) )
			show_error('Invalid characters in file name.');

		$config['upload_path'] = $user_dir;
		$config['allowed_types'] = '*';
		$config['max_size']	= $this->settings_model->get_setting('file_size_limit');
		$config['file_name'] = $this->file_name."-".($this->user->selected_assignment['total_submits']+1).".".$this->ext;
		$config['max_file_name'] = 20;
		$config['remove_spaces'] = TRUE;
		$this->upload->initialize($config);

		if ($this->upload->do_upload('userfile'))
		{
			$result = $this->upload->data();
			$this->load->model('submit_model');

			$submit_info = array(
				'submit_id' => $this->assignment_model->increase_total_submits($this->user->selected_assignment['id']),
				'username' => $this->user->username,
				'assignment' => $this->user->selected_assignment['id'],
				'problem' => $this->problem['id'],
				'file_name' => $result['raw_name'],
				'main_file_name' => $this->file_name,
				'file_type' => $this->filetype,
				'coefficient' => $this->coefficient,
				'pre_score' => 0,
				'time' => shj_now_str(),
			);
			if ($this->problem['is_upload_only'] == 0)
			{
				$this->queue_model->add_to_queue($submit_info);
				process_the_queue();
			}
			else
			{
				$this->submit_model->add_upload_only($submit_info);
			}

			return TRUE;
		}

		return FALSE;
	}



}
