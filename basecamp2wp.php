<?php
/*
Plugin Name: Basecamp2WP
Description: Imports Basecamp data into WP using WP Project Manager
Version: 1.0
*/

include "admin.php";

/**
 * Imports Basecamp data into WP using WP Project Manager
 */
class Basecamp2WP {

  private $basecamp_user;
  private $basecamp_password;
  private $basecamp_id;
  private $base_url;
  private $args;

  function Basecamp2WP() {
    $this->basecamp_user = get_option('lsp_basecamp_user');
    $this->basecamp_password = get_option('lsp_basecamp_password');
    $this->basecamp_id = get_option('lsp_basecamp_id');
    $this->base_url = 'https://basecamp.com/'.$this->basecamp_id.'/api/v1';
    $this->args = array(
      'headers' => array(
        'Authorization' => 'Basic ' . base64_encode( $this->basecamp_user . ':' . $this->basecamp_password )
      )
    );

    $this->init();

  }


  /**
   *  Init functions
   */
  function init() {
    set_time_limit(0);
    $this->people();
    $this->projects();
  }

  /**
   * Import Basecamp users
   */
  function people() {
    // Import active and deleted people
    $urls = array($this->base_url.'/people.json', $this->base_url.'/people/trashed.json');
    foreach($urls as $url) {
      // Access to Basecamp API
      $response = wp_remote_get( $url, $this->args );
      $people = json_decode($response['body']);
      foreach($people as $p) {
        if (!get_user_by('email', $p->email_address)) {
          echo '<p><strong>'.$p->name.'</strong></p>';
          // New WP user data
          $userdata = array(
            'display_name' => $p->name,
            'user_nicename' => $p->name,
            'first_name' => preg_replace('#(.*)\s(.*)#', '$1', $p->name),
            'last_name' => preg_replace('#(.*)\s(.*)#', '$2', $p->name),
            'user_login' => sanitize_title($p->name),
            'user_email' => $p->email_address,
            'user_pass' => 'passwordprovisional'
          );
          // Create user
          $user_id = wp_insert_user( $userdata ) ;
          // Associate basecamp_id to new user
          update_user_meta($user_id, 'basecamp_id', $p->id);
          // Uploads avatar
          $media_id = $this->upload_url($p->fullsize_avatar_url, $p->name);
          update_user_meta($user_id, 'basecamp_image_media_id', $media_id);
        }
      }
    }
  }


  /**
   * Imports Basecamp Projects
   */
  function projects() {
    global $wpdb;

    // Active and deleted projects
    $urls = array($this->base_url.'/projects.json', $this->base_url.'/projects/archived.json');
    foreach($urls as $_url) {
      // Repeat for each data page
      $page = 0;
      do {
        $page++;
        if($page > 1) $url = $_url.'?page='.$page;
        else $url = $_url;
        // Gets projects
        $response = wp_remote_get( $url, $this->args );
        $projects = json_decode($response['body']);
        foreach($projects as $_p) {
          $response = wp_remote_get( $_p->url, $this->args );
          $p = json_decode($response['body']);
          echo '<p><strong>'.__('Project', 'displaynone').'</strong>: '.$p->name.'</p>';
          $project_id = $wpdb->get_var($wpdb->prepare("SELECT ID from {$wpdb->posts} p, {$wpdb->postmeta} pm where p.ID = pm.post_id and p.post_type = 'cpm_project' and pm.meta_key = 'basecamp_id' and pm.meta_value = %s", $p->id));
          // If no exists
          if (!$project_id) {
            $data = array(
              'project_name' => $p->name,
              'project_description' => $p->description?$p->description:''
            );
            // Create new WP Manager project
            $project_id = cpm()->project->create( 0, $data );
            update_post_meta($project_id, 'basecamp_id', $p->id);
          }
          $_POST['project_id'] = $project_id;

          // Update CPM Project with Basecamp project data
          $project = get_post($project_id);
          $project->post_date = $p->created_at;
          $protect->post_date_gmt = $p->created_at;
          $project->post_modified = $p->updated_at;
          $project->post_modified_gmt = $p->updated_at;
          $wpdb->update($wpdb->posts, array('post_date' => $p->created_at, 'post_date_gmt' => $p->created_at, 'post_modified' => $p->updated_at, 'post_modified_gmt' => $p->updated_at), array('ID' => $project_id));


          // CPM Project Creator
          $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $p->creator->id));
          if ($user_id) {
            $project->post_author = $user_id;
            wp_update_post($project);
            cpm()->project->insert_user($project_id, $user_id, 'manager');
          }

          // Insert users into the project
          $response = wp_remote_get( $p->accesses->url, $this->args );
          $accesses = json_decode($response['body']);
          foreach($accesses as $access) {
            $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $access->id));
            if ($user_id && $user_id != $project->post_author) {
              if ($access->is_client) $role = 'client';
              else if ($access->admin) $role = 'manager';
              else $role = 'co-worker';
              cpm()->project->insert_user($project_id, $user_id, $role);
            }
          }

          // Todo Lists
          $url = $p->todolists->url;
          $page = 0;
          do {
            $page++;
            $response = wp_remote_get( $page > 1?$url.'?page='.$page:$url, $this->args );
            $todolists = json_decode($response['body']);
            foreach($todolists as $tl) {
              $tasklist_id = $this->create_tasklist($project_id, $tl);
            }
          } while (count($todolists) == 50);

          // Todos
          $url = str_replace('todolists', 'todos', $p->todolists->url);
          $page = 0;
          do {
            $page++;
            $response = wp_remote_get( $page > 1?$url.'?page='.$page:$url, $this->args );
            $todos = json_decode($response['body']);
            foreach($todos as $t) {
              $tasklist_id = $this->create_tasklist($project_id, $t->todolist);
              $task_id = $this->create_task($tasklist_id, $t);
            }
          } while (count($todos) == 50);

          // Messages
          $url = $p->topics->url;
          $page = 0;
          do {
            $page++;
            $response = wp_remote_get( $page > 1?$url.'?page='.$page:$url, $this->args );
            $messages = json_decode($response['body']);
            foreach($messages as $m) {
              if ($m->topicable->type == 'Message') {
                $task_id = $this->create_message($project_id, $m);
                exit();
              }
            }
            var_dump (count($messages)); echo __LINE__; exit();
          } while (count($messages) == 50);
        }
      } while (count($projects) == 50);
    }
  }

  /**
   * Create a new tasklist
   *
   * @param int $project_id CPM Project ID
   * @param object $tl TO-DO List
   * @return int $tasklist_id
   */
  function create_tasklist($project_id, $tl) {
    global $wpdb;

    $tasklist_id = $wpdb->get_var($wpdb->prepare("SELECT ID from {$wpdb->posts} p, {$wpdb->postmeta} pm where p.ID = pm.post_id and p.post_type = 'cpm_task_list' and pm.meta_key = 'basecamp_id' and pm.meta_value = %s", $tl->id));
    if (!$tasklist_id) {
      echo '<p><strong>Task list</strong>:'.$tl->name.'</p>';
      $data['tasklist_name'] = $tl->name;
      $data['tasklist_detail'] = $tl->description?$tl->description:'';
      $tasklist_id = cpm()->task->add_list( $project_id, $data );
      $wpdb->update($wpdb->posts, array('post_date' => $tl->created_at, 'post_date_gmt' => $tl->created_at, 'post_modified' => $tl->updated_at, 'post_modified_gmt' => $tl->updated_at), array('ID' => $tasklist_id));
      update_post_meta($tasklist_id, 'basecamp_id', $tl->id);
    }
    return $tasklist_id;
  }

  /**
   * Create a new task
   *
   * @param int $tasklist_id CPM Task list
   * @param object $_t Task
   * @return int $task_id
   */
  function create_task($tasklist_id, $_t) {
    global $wpdb;

    $response = wp_remote_get( $_t->url, $this->args );
    $t = json_decode($response['body']);

    $task_id = $wpdb->get_var($wpdb->prepare("SELECT ID from {$wpdb->posts} p, {$wpdb->postmeta} pm where p.ID = pm.post_id and p.post_type = 'cpm_task' and pm.meta_key = 'basecamp_id' and pm.meta_value = %s", $t->id));
    if (!$task_id) {
      echo '<p><strong>Task</strong>:'.$_t->name.'</p>';
      $data = array();
      $data['task_title'] = $t->content;
      $data['task_text'] = '';
      $asigned = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $t->assignee->id));
      $data['task_assign'] = array($asigned);
      $user = get_user_by( 'id', $asigned );
      if( $user ) {
        wp_set_current_user( $asigned, $user->user_login );
      }
      $task_id = cpm()->task->add_task( $tasklist_id, $data);
      update_post_meta($task_id, 'basecamp_id', $t->id);
      if ($t->completed) {
        update_post_meta( $task_id, '_completed', 1 );
        $completer = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $t->completer->id));
        update_post_meta( $task_id, '_completed_by', $completer );
        update_post_meta( $task_id, '_completed_on', strftime('%Y-%m-%d %H:%M:%S', strtotime($t->completed_at) ));
      }

      // Comments
      echo "<p><strong>Comments</strong></p>";
      foreach($t->comments as $c) {
        $commenter_id = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $c->creator->id));
        $user = get_user_by( 'id', $commenter_id );
        if( $user ) {
          wp_set_current_user( $commenter_id, $user->user_login );
        }
        $comment_data = array();
        echo '<p>'.substr($c->content, 0, 25).'...</p>';
        $comment_data['comment_content'] = $c->content;
        $comment_data['comment_post_ID'] = $task_id;
        $comment_data['comment_post_ID'] = $task_id;
        $files = array();
        foreach($c->attachments as $f) {
          $fid = $this->upload_url($f->url, $f->name);
          $wpdb->update($wpdb->posts, array('post_date' => $f->created_at, 'post_date_gmt' => $f->created_at, 'post_modified' => $f->updated_at, 'post_modified_gmt' => $f->updated_at), array('ID' => $fid));
          $files[] = $fid;
        }
        $comment = CPM_Comment::getInstance();
        $comment_id = $comment->create( $comment_data, $files );
        $wpdb->update($wpdb->comments, array('comment_date' => $c->created_at, 'comment_date_gmt' => $c->created_at, 'user_id'=>$commenter_id), array('comment_ID' => $comment_id));
      }

      $task = get_post($task_id);

      $creator = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $t->creator->id));
      $task->post_author = $creator;

      $task->post_date = $t->created_at;
      $task->post_date_gmt = $t->created_at;
      $task->post_modified = $t->updated_at;
      $task->post_modified_gmt = $t->updated_at;
      $wpdb->update($wpdb->posts, array('post_date' => $t->created_at, 'post_date_gmt' => $t->created_at, 'post_modified' => $t->updated_at, 'post_modified_gmt' => $t->updated_at), array('ID' => $task_id));
      update_post_meta( $task_id, '_start', cpm_date2mysql( $t->created_at ) );

      wp_update_post($task);
    }

    return $task_id;
  }

  /**
   * Create a new message
   *
   * @param int $project_id CPM Project id
   * @param object $_t Message
   * @return int $task_id
   */
  function create_message($project_id, $_m) {
    global $wpdb;

    $response = wp_remote_get( $_m->topicable->url, $this->args );
    $m = json_decode($response['body']);

    $message_id = $wpdb->get_var($wpdb->prepare("SELECT ID from {$wpdb->posts} p, {$wpdb->postmeta} pm where p.ID = pm.post_id and p.post_type = 'cpm_message' and pm.meta_key = 'basecamp_id' and pm.meta_value = %s", $m->id));
    if (!$message_id) {
      echo '<p><strong>Message</strong>:'.$_m->subject.'</p>';
      $data = array();
      $data['message_title'] = $m->subject;
      $data['message_detail'] = $m->content;
      $creator = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $m->creator->id));
      $user = get_user_by( 'id', $creator );
      if( $user ) {
        wp_set_current_user( $creator, $user->user_login );
      }
      $message_id = cpm()->message->create( $project_id, $data);
      update_post_meta($message_id, 'basecamp_id', $m->id);

      // Comments
      echo "<p><strong>Comments</strong></p>";
      foreach($m->comments as $c) {
        $commenter_id = $wpdb->get_var($wpdb->prepare("SELECT user_id from {$wpdb->usermeta} WHERE meta_key = 'basecamp_id' and meta_value = %s", $c->creator->id));
        $user = get_user_by( 'id', $commenter_id );
        if( $user ) {
          wp_set_current_user( $commenter_id, $user->user_login );
        }
        $comment_data = array();
        echo '<p>'.substr($c->content, 0, 25).'...</p>';
        $comment_data['comment_content'] = $c->content;
        $comment_data['comment_post_ID'] = $message_id;
        $files = array();
        foreach($c->attachments as $f) {
          $fid = $this->upload_url($f->url, $f->name);
          $wpdb->update($wpdb->posts, array('post_date' => $f->created_at, 'post_date_gmt' => $f->created_at, 'post_modified' => $f->updated_at, 'post_modified_gmt' => $f->updated_at), array('ID' => $fid));
          $files[] = $fid;
        }
        $comment = CPM_Comment::getInstance();
        $comment_id = $comment->create( $comment_data, $files );
        $wpdb->update($wpdb->comments, array('comment_date' => $c->created_at, 'comment_date_gmt' => $c->created_at, 'user_id'=>$commenter_id), array('comment_ID' => $comment_id));
      }

      $message = get_post($message_id);

      $message->post_author = $creator;

      $message->post_date = $m->created_at;
      $message->post_date_gmt = $m->created_at;
      $message->post_modified = $m->updated_at;
      $message->post_modified_gmt = $m->updated_at;
      $wpdb->update($wpdb->posts, array('post_date' => $m->created_at, 'post_date_gmt' => $m->created_at, 'post_modified' => $m->updated_at, 'post_modified_gmt' => $m->updated_at), array('ID' => $message_id));

      wp_update_post($message);
    }

    return $message_id;
  }



  /**
   * Upload content from URL
   *
   * @param string $url file URL
   * @param string $desc file Description
   * @return int $id new file id
   */
  function upload_url($url, $desc = '') {
  	// Need to require these files
  	if ( !function_exists('media_handle_upload') ) {
  		require_once(ABSPATH . "wp-admin" . '/includes/image.php');
  		require_once(ABSPATH . "wp-admin" . '/includes/file.php');
  		require_once(ABSPATH . "wp-admin" . '/includes/media.php');
  	}

    $tmpfname = wp_tempnam($url);
    if ( ! $tmpfname ) return new WP_Error('http_no_file', __('Could not create Temporary file.'));

    $tmpresponse = wp_remote_get( $url, array( 'timeout' => 600, 'stream' => true, 'filename' => $tmpfname, 'headers' => array(
      'Authorization' => 'Basic ' . base64_encode( $this->basecamp_user . ':' . $this->basecamp_password )
    ) ) );
  	if( is_wp_error( $tmpresponse ) ){
      exit();
  	}
  	$post_id = null;
  	$file_array = array();

  	// Set variables for storage
  	// fix file filename for query strings
  	preg_match('/[^\?]+\.(.*)$/i', $url, $matches);
  	$file_array['name'] = basename($matches[0]);
  	$file_array['tmp_name'] = $tmpfname;

  	// If error storing temporarily, unlink
  	if ( is_wp_error( $tmpfname ) ) {
  		@unlink($file_array['tmp_name']);
  		$file_array['tmp_name'] = '';
  	}

  	// do the validation and storage stuff
  	$id = media_handle_sideload( $file_array, $post_id, $desc );
  	// If error storing permanently, unlink
  	if ( is_wp_error($id) ) {
  		@unlink($file_array['tmp_name']);
  	}

    return $id;
  }

}
