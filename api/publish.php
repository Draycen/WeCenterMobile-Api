<?php
if (!defined('IN_ANWSION'))
{
	die;
}

class publish extends AWS_CONTROLLER
{
	public function get_access_rule()
	{
		$rule_action['rule_type'] = 'white'; //黑名单,黑名单中的检查  'white'白名单,白名单以外的检查
		 $rule_action['actions'] = array(
                                'attach_upload',
                                'publish_question',
                                'save_answer',
                                'save_comment'
                        );
		
		return $rule_action;
	}
	
	public function setup()
	{
		HTTP::no_cache_header();
	}

	
	//上传附件
	//GET  id(question,article,answer[默认]
	public function attach_upload_action()
	{
		
		//$_GET['attach_access_key'] = md5( time().rand(99,999) );  //接口生成
		if( empty($_GET['id']) ) $_GET['id'] = 'answer';  //默认

		switch ($_GET['id'])
		{
			case 'question':
				$item_type = 'questions';
			break;
			
			case 'article':
				$item_type = 'article';
			break;
			
			default:
				$item_type = 'answer';
				
				$_GET['id'] = 'answer';
			break;
		}
		
		AWS_APP::upload()->initialize(array(
			'allowed_types' => get_setting('allowed_upload_types'),
			'upload_path' => get_setting('upload_dir') . '/' . $item_type . '/' . gmdate('Ymd'),
			'is_image' => FALSE,
			'max_size' => get_setting('upload_size_limit')
		));
		
		if (isset($_GET['qqfile']))
		{
			AWS_APP::upload()->do_upload($_GET['qqfile'], true);
		}
		else if (isset($_FILES['qqfile']))
		{
			AWS_APP::upload()->do_upload('qqfile');
		}
		else
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t( '请选择要上传的文件' )));
		}
				
		if (AWS_APP::upload()->get_error())
		{
			switch (AWS_APP::upload()->get_error())
			{
				default:
					H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t( '错误代码: '.AWS_APP::upload()->get_error() )));
				break;
				
				case 'upload_invalid_filetype':
					H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t( '文件类型无效' )));
				break;	
				
				case 'upload_invalid_filesize':
					H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t( "文件尺寸过大,最大允许尺寸为 ".get_setting('upload_size_limit')." KB" )));
				break;
			}
		}
		
		if (! $upload_data = AWS_APP::upload()->data())
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t( '上传失败, 请与管理员联系' )));
		}
		
		if ($upload_data['is_image'] == 1)
		{
			foreach(AWS_APP::config()->get('image')->attachment_thumbnail AS $key => $val)
			{			
				$thumb_file[$key] = $upload_data['file_path'] . $val['w'] . 'x' . $val['h'] . '_' . basename($upload_data['full_path']);
				
				AWS_APP::image()->initialize(array(
					'quality' => 90,
					'source_image' => $upload_data['full_path'],
					'new_image' => $thumb_file[$key],
					'width' => $val['w'],
					'height' => $val['h']
				))->resize();	
			}
		}
		
		$attach_id = $this->model('publish')->add_attach($_GET['id'], $upload_data['orig_name'], $_GET['attach_access_key'], time(), basename($upload_data['full_path']), $upload_data['is_image']);
		
		$output = array(
			'attach_access_key' => $_GET['attach_access_key'],
			'attach_id' => $attach_id,
		);
		
		$attach_info = $this->model('publish')->get_attach_by_id($attach_id);
		
		if ($attach_info['thumb'])
		{
			$output['thumb'] = $attach_info['thumb'];
		}
		else
		{
			$output['class_name'] = $this->model('publish')->get_file_class(basename($upload_data['full_path']));
		}
		
		 H::ajax_json_output(AWS_APP::RSM($output, 1, null));
	}

	//发布问题
	// POST  question_content,question_detail,topics(数组),anonymous(选填,如果匿名值为1,暂时不允许),attach_access_key(选)
	public function publish_question_action()
	{
		if (!$this->user_info['permission']['publish_question'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限发布问题')));
		}
		
		if (empty($_POST['question_content']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入问题标题')));
		}
		
		//if (get_setting('category_enable') == 'N')
		//{
			$_POST['category_id'] = 1;  //移动版默认为1
		//}
		
		/*if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择问题分类')));
		}*/

		if (cjk_strlen($_POST['question_content']) < 5)
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('问题标题字数不得少于 5 个字')));
		}

		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['question_content']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题标题字数不得大于 %s 字节', get_setting('question_title_limit'))));
		}
		
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['question_detail']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		/*if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{			
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}*/
		
		if ($_POST['topics'] AND get_setting('question_topics_limit') AND sizeof($_POST['topics']) > get_setting('question_topics_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('单个问题话题数量最多为 %s 个, 请调整话题数量', get_setting('question_topics_limit'))));
		}
		
		if (get_setting('new_question_force_add_topic') == 'Y' AND !$_POST['topics'] AND get_setting('category_enable') == 'N')
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请为问题添加话题')));
		}

		$_POST['topics'] = explode(',',$_POST['topics']); //英文逗号隔开
		
		// !注: 来路检测后面不能再放报错提示
		/*
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}*/
		
		//$this->model('draft')->delete_draft(1, 'question', $this->user_id);
		
		$question_id = $this->model('publish')->publish_question($_POST['question_content'], $_POST['question_detail'], $_POST['category_id'], $this->user_id, $_POST['topics'], $_POST['anonymous'], $_POST['attach_access_key'], $_POST['ask_user_id'], $this->user_info['permission']['create_topic']);	
		H::ajax_json_output(AWS_APP::RSM(array(
				'question_id' => $question_id
			), 1, null));
	}

	//回答问题 
	// POST  question_id,answer_content,attach_access_key(可选)
	public function save_answer_action(){
		
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
		
		if ($question_info['lock'] AND ! ($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的问题不能回复')));
		}
		
		$answer_content = trim($_POST['answer_content'], "\r\n\t");
		
		if (! $answer_content)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请输入回复内容')));
		}
		
		// 判断是否是问题发起者
		if (get_setting('answer_self_question') == 'N' and $question_info['published_uid'] == $this->user_id)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('不能回复自己发布的问题，你可以修改问题内容')));
		}
		
		// 判断是否已回复过问题
		if ((get_setting('answer_unique') == 'Y') && $this->model('answer')->has_answer_by_uid($_POST['question_id'], $this->user_id))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('一个问题只能回复一次，你可以编辑回复过的回复')));
		}
		
		if (strlen($answer_content) < get_setting('answer_length_lower'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复内容字数不得少于 %s 字节', get_setting('answer_length_lower'))));
		}
		
		if (! $this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($answer_content))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		$answer_id = $this->model('publish')->publish_answer($_POST['question_id'], $answer_content, $this->user_id, $_POST['anonymous'], $_POST['attach_access_key'], $_POST['auto_focus']);
					
		H::ajax_json_output(AWS_APP::RSM(array(
			'answer_id' => $answer_id
		), 1, null));	
	}

	//对文章进行评论
	// POST  article_id,message,at_uid(可选)
	public function save_comment_action()
	{
		if (!$article_info = $this->model('article')->get_article_info_by_id($_POST['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('指定文章不存在')));
		}
		
		if ($article_info['lock'] AND ! ($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的文章不能回复')));
		}
		
		$message = trim($_POST['message'], "\r\n\t");
		
		if (! $message)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请输入回复内容')));
		}
		
		if (strlen($message) < get_setting('answer_length_lower'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复内容字数不得少于 %s 字节', get_setting('answer_length_lower'))));
		}
		
		if (! $this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($message))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		
		$comment_id = $this->model('publish')->publish_article_comment($_POST['article_id'], $message, $this->user_id, $_POST['at_uid']);
			

		H::ajax_json_output(AWS_APP::RSM(array(
				'comment_id' => $comment_id
		), 1, null));
	}


	
	public function article_attach_edit_list_action()
	{
		if (! $article_info = $this->model('article')->get_article_info_by_id($_POST['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('无法获取附件列表')));
		}
		
		if ($article_info['uid'] != $this->user_id AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个附件列表')));
		}
		
		if ($article_attach = $this->model('publish')->get_attach('article', $_POST['article_id']))
		{
			foreach ($article_attach as $attach_id => $val)
			{
				$article_attach[$attach_id]['class_name'] = $this->model('publish')->get_file_class($val['file_name']);
				
				$article_attach[$attach_id]['delete_link'] = get_js_url('/publish/ajax/remove_attach/attach_id-' . base64_encode(H::encode_hash(array(
					'attach_id' => $attach_id, 
					'access_key' => $val['access_key']
				))));
				
				$article_attach[$attach_id]['attach_id'] = $attach_id;
				$article_attach[$attach_id]['attach_tag'] = 'attach';
			}
		}
		
		H::ajax_json_output(AWS_APP::RSM(array(
			'attachs' => $article_attach
		), 1, null));
	}
	
	public function question_attach_edit_list_action()
	{
		if (! $question_info = $this->model('question')->get_question_info_by_id($_POST['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('无法获取附件列表')));
		}
		
		if ($question_info['published_uid'] != $this->user_id AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个附件列表')));
		}
		
		if ($question_attach = $this->model('publish')->get_attach('question', $_POST['question_id']))
		{
			foreach ($question_attach as $attach_id => $val)
			{
				$question_attach[$attach_id]['class_name'] = $this->model('publish')->get_file_class($val['file_name']);
				
				$question_attach[$attach_id]['delete_link'] = get_js_url('/publish/ajax/remove_attach/attach_id-' . base64_encode(H::encode_hash(array(
					'attach_id' => $attach_id, 
					'access_key' => $val['access_key']
				))));
				
				$question_attach[$attach_id]['attach_id'] = $attach_id;
				$question_attach[$attach_id]['attach_tag'] = 'attach';
			}
		}
		
		H::ajax_json_output(AWS_APP::RSM(array(
			'attachs' => $question_attach
		), 1, null));
	}

	public function answer_attach_edit_list_action()
	{
		if (!$answer_info = $this->model('answer')->get_answer_by_id($_POST['answer_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复不存在')));
		}
		
		if ($answer_info['uid'] != $this->user_id AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个附件列表')));
		}
		
		if ($answer_attach = $this->model('publish')->get_attach('answer', $_POST['answer_id']))
		{
			foreach ($answer_attach as $attach_id => $val)
			{
				$answer_attach[$attach_id]['class_name'] = $this->model('publish')->get_file_class($val['file_name']);
				$answer_attach[$attach_id]['delete_link'] = get_js_url('/publish/ajax/remove_attach/attach_id-' . base64_encode(H::encode_hash(array(
					'attach_id' => $attach_id, 
					'access_key' => $val['access_key']
				))));
				
				$answer_attach[$attach_id]['attach_id'] = $attach_id;
				$answer_attach[$attach_id]['attach_tag'] = 'attach';
			}
		}
				
		H::ajax_json_output(AWS_APP::RSM(array(
			'attachs' => $answer_attach
		), 1, null));
	}

	public function remove_attach_action()
	{
		if ($attach_info = H::decode_hash(base64_decode($_GET['attach_id'])))
		{
			$this->model('publish')->remove_attach($attach_info['attach_id'], $attach_info['access_key']);
		}
		
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}

	public function modify_question_action()
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
		
		if ($question_info['lock'] && !($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题已锁定, 不能编辑')));
		}
		
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_question'])
		{			
			if ($question_info['published_uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个问题')));
			}
		}
		
		if (!$_POST['category_id'] AND get_setting('category_enable') == 'Y')
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请选择分类')));
		}

		if (cjk_strlen($_POST['question_content']) < 5)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题标题字数不得少于 5 个字')));
		}

		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['question_content']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题标题字数不得大于') . ' ' . get_setting('question_title_limit') . ' ' . AWS_APP::lang()->_t('字节')));
		}
		
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['question_detail']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
		
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
		
		$this->model('draft')->delete_draft(1, 'question', $this->user_id);
		
		if ($_POST['do_delete'] AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{				
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有删除问题的权限')));
		}
		
		if ($_POST['do_delete'])
		{
			if ($this->user_id != $question_info['published_uid'])
			{
				$this->model('account')->send_delete_message($question_info['published_uid'], $question_info['question_content'], $question_info['question_detail']);
			}
				
			$this->model('question')->remove_question($question_info['question_id']);
			
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/home/explore/')
			), 1, null));
		}
		
		$IS_MODIFY_VERIFIED = TRUE;
		
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'] AND $question_info['published_uid'] != $this->user_id)
		{
			$IS_MODIFY_VERIFIED = FALSE;
		}
			
		$this->model('question')->update_question($question_info['question_id'], $_POST['question_content'], $_POST['question_detail'], $this->user_id, $IS_MODIFY_VERIFIED, $_POST['modify_reason'], $question_info['anonymous'], $_POST['category_id']);
		
		if ($this->user_id != $question_info['published_uid'])
		{
			$this->model('question')->add_focus_question($question_info['question_id'], $this->user_id);
			
			$this->model('notify')->send($this->user_id, $question_info['published_uid'], notify_class::TYPE_MOD_QUESTION, notify_class::CATEGORY_QUESTION, $question_info['question_id'], array(
				'from_uid' => $this->user_id,
				'question_id' => $question_info['question_id']
			));
			
			$this->model('email')->action_email('QUESTION_MOD', $question_info['published_uid'], get_js_url('/question/' . $question_info['question_id']), array(
				'user_name' => $this->user_info['user_name'], 
				'question_title' => $question_info['question_content']
			));
		}
		
		if ($_POST['category_id'] AND $_POST['category_id'] != $question_info['category_id'])
		{
			$category_info = $this->model('system')->get_category_info($_POST['category_id']);
			
			ACTION_LOG::save_action($this->user_id, $question_info['question_id'], ACTION_LOG::CATEGORY_QUESTION, ACTION_LOG::MOD_QUESTION_CATEGORY, $category_info['title'], $category_info['id']);
		}
		
		if ($_POST['attach_access_key'] AND $IS_MODIFY_VERIFIED)
		{
			if ($this->model('publish')->update_attach('question', $question_info['question_id'], $_POST['attach_access_key']))
			{
				ACTION_LOG::save_action($this->user_id, $question_info['question_id'], ACTION_LOG::CATEGORY_QUESTION, ACTION_LOG::MOD_QUESTION_ATTACH);
			}
		}
		
		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/question/' . $question_info['question_id'] . '?column=log&rf=false')
		), 1, null));
	
	}
	
	
	
	public function publish_article_action()
	{
		if (!$this->user_info['permission']['publish_article'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限发布文章')));
		}
		
		if (empty($_POST['title']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入文章标题')));
		}
		
		if (get_setting('category_enable') == 'N')
		{
			$_POST['category_id'] = 1;
		}
		
		if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择文章分类')));
		}
		
		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['title']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章标题字数不得大于 %s 字节', get_setting('question_title_limit'))));
		}
		
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['message']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{			
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
		
		if ($_POST['topics'] AND get_setting('question_topics_limit') AND sizeof($_POST['topics']) > get_setting('question_topics_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('单个文章话题数量最多为 %s 个, 请调整话题数量', get_setting('question_topics_limit'))));
		}
		
		if (get_setting('new_question_force_add_topic') == 'Y' AND !$_POST['topics'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请为文章添加话题')));
		}
		
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
		
		$this->model('draft')->delete_draft(1, 'article', $this->user_id);
		
		if ($this->publish_approval_valid())
		{			
			$this->model('publish')->publish_approval('article', array(
				'title' => $_POST['title'],
				'message' => $_POST['message'],
				'category_id' => $_POST['category_id'],
				'topics' => $_POST['topics'],
				'permission_create_topic' => $this->user_info['permission']['create_topic']
			), $this->user_id, $_POST['attach_access_key']);
				
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/wait_approval/')
			), 1, null));
		}
		else
		{
			$article_id = $this->model('publish')->publish_article($_POST['title'], $_POST['message'], $this->user_id, $_POST['topics'], $_POST['category_id'], $_POST['attach_access_key'], $this->user_info['permission']['create_topic']);
		
			if ($_POST['_is_mobile'])
			{
				$url = get_js_url('/m/article/' . $article_id);
			}
			else
			{
				$url = get_js_url('/article/' . $article_id);
			}
				
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => $url
			), 1, null));
		}
	}
	
	function modify_article_action()
	{
		if (!$article_info = $this->model('article')->get_article_info_by_id($_POST['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章不存在')));
		}
		
		if ($article_info['lock'] && !($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章已锁定, 不能编辑')));
		}
		
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_article'])
		{			
			if ($article_info['uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个文章')));
			}
		}
		
		if (empty($_POST['title']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入文章标题')));
		}
		
		if (get_setting('category_enable') == 'N')
		{
			$_POST['category_id'] = 1;
		}
		
		if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择文章分类')));
		}

		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['title']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章标题字数不得大于') . ' ' . get_setting('question_title_limit') . ' ' . AWS_APP::lang()->_t('字节')));
		}
		
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['message']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
		
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
		
		$this->model('draft')->delete_draft(1, 'article', $this->user_id);
		
		if ($_POST['do_delete'] AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{				
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有删除文章的权限')));
		}
		
		if ($_POST['do_delete'])
		{
			if ($this->user_id != $article_info['uid'])
			{
				$this->model('account')->send_delete_message($article_info['uid'], $article_info['title'], $article_info['message']);
			}
				
			$this->model('article')->remove_article($article_info['id']);
			
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/home/explore/')
			), 1, null));
		}
		
		$this->model('article')->update_article($article_info['id'], $_POST['title'], $_POST['message'], $_POST['topics'], $_POST['category_id'], $this->user_info['permission']['create_topic']);
		
		if ($_POST['attach_access_key'])
		{
			$this->model('publish')->update_attach('article', $article_info['id'], $_POST['attach_access_key']);
		}
		
		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/article/' . $article_info['id'])
		), 1, null));
	}
	
	public function save_related_link_action()
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['item_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
		
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			if ($question_info['published_uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限执行该操作')));
			}
		}
		
		if (substr($_POST['link'], 0, 7) != 'http://' AND substr($_POST['link'], 0, 8) != 'https://')
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('链接格式不正确')));
		}
		
		$this->model('related')->add_related_link($this->user_id, 'question', $_POST['item_id'], $_POST['link']);
		
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
	
	public function remove_related_link_action()
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['item_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
		
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			if ($question_info['published_uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限执行该操作')));
			}
		}
		
		$this->model('related')->remove_related_link($_POST['id'], $_POST['item_id']);
		
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
}