<?php

class NGPVolunteerFrontend {
    
    // The API Key for NGP (should be a superlong string.)
    var $api_key = '';
    
    // Is set to true when there's a problem communicating
    // with the NGP API or NGP API returns an error for an
    // attempted contribution
    var $ngp_error = false;
    
    // Is set to the url specified in the WP General Settings
    // This is the Domain that the SSL cert for your server is keyed to.
    // Example: donate.yourdomain.com, yourdomain.com, or www.yourdomain.com
    // OPTIONAL
    var $url_specified = '';
    
    // Set to true when errors are found in the form itself
    // (Set before we even try to send it to NGP)
    var $any_errors = false;
    
    // Set to try when we have processed the form during the current run.
    var $been_processed = false;
    
    // The default redirect URL for the thank-you page.
    var $redirect_url = '/thank-you-for-volunteering';
    
    // Populated with the NGP fieldsets
    var $fieldsets = array();
    
    // Support phone for error messages.
    var $support_phone = '';
    
    /*
     * Construct
     * Here we populate many of the above vars from the WP options.
     */
    function __construct() {
        $this->api_key = get_option('ngp_api_key', '');
        $this->support_phone = get_option('ngp_support_phone', '');
        $this->redirect_url = get_option('ngp_volunteer_thanks_url', '/thank-you-for-volunteering');
        
        $this->fields = array(
            array(
                'type' => 'text',
                'slug' => 'FullName',
                'required' => 'true',
                'label' => 'Name',
            ),
            array(
                'type' => 'text',
                'slug' => 'Email',
                'required' => 'false',
                'label' => 'Email Address'
            ),
            array(
                'type' => 'text',
                'slug' => 'Phone',
                'required' => 'false',
                'label' => 'Phone'
            ),
            array(
                'type' => 'text',
                'slug' => 'Address1',
                'required' => 'true',
                'label' => 'Street Address'
            ),
            // array(
            //     'type' => 'text',
            //     'slug' => 'Address2',
            //     'required' => 'false',
            //     'label' => 'Address (Cont.)'
            //     'show_label' => 'false'
            // ),
            array(
                'type' => 'hidden',
                'slug' => 'City',
                // 'required' => 'true',
                // 'label' => 'City'
            ),
            array(
                'type' => 'hidden',
                'slug' => 'State',
                // 'required' => 'true',
                // 'label' => 'State',
                // 'options' => array('AK'=>'AK','AL'=>'AL','AR'=>'AR','AZ'=>'AZ','CA'=>'CA','CO'=>'CO','CT'=>'CT','DC'=>'DC','DE'=>'DE','FL'=>'FL','GA'=>'GA','HI'=>'HI','IA'=>'IA','ID'=>'ID','IL'=>'IL','IN'=>'IN','KS'=>'KS','KY'=>'KY','LA'=>'LA','MA'=>'MA','MD'=>'MD','ME'=>'ME','MI'=>'MI','MN'=>'MN','MO'=>'MO','MS'=>'MS','MT'=>'MT','NC'=>'NC','ND'=>'ND','NE'=>'NE','NH'=>'NH','NJ'=>'NJ','NM'=>'NM','NV'=>'NV','NY'=>'NY','OH'=>'OH','OK'=>'OK','OR'=>'OR','PA'=>'PA','RI'=>'RI','SC'=>'SC','SD'=>'SD','TN'=>'TN','TX'=>'TX','UT'=>'UT','VA'=>'VA','VT'=>'VT','WA'=>'WA','WI'=>'WI','WV'=>'WV','WY'=>'WY')
            ),
            array(
                'type' => 'text',
                'slug' => 'Zip',
                'required' => 'true',
                'label' => 'Zip Code'
            ),
        );
    }
    
    /*
     * Check Configuration
     */
    function check_config() {
        global $wpdb, $ngp;
        if(empty($this->api_key)) {
            return 'Not currently configured.';
            exit();
        }
        return true;
    }
    
    /* Submits and reroutes donation form */
    function process_form() {
        global $wpdb, $ngp;
        if($this->been_processed) { return false; exit(); }
    
        if(!empty($_POST)) {
            if(wp_verify_nonce($_POST['ngp_volunteer'], 'ngp_nonce_field')) // && $_POST['ngp_form_id']==$id
            {
                foreach($this->fields as $key => $field) {
                    if($field['required']=='true' && (!isset($_POST[$field['slug']]) || empty($_POST[$field['slug']]))) {
                        $this->fields[$key]['error'] = true;
                        $this->any_errors = true;
                    }
                }
                
                if(!$this->any_errors) {
                    $namePrefixes = array('Dr', 'Hon', 'Mr', 'Mrs', 'Ms', 'Prof', 'Rep', 'Rev');
                    $nameSuffixes = array(
                        'Jr'        =>    'Jr',
                        'Junior'    =>    'Jr',
                        'Senior'    =>    'Sr',
                        'Sr'        =>    'Sr',
                        'I'            =>    'I',
                        'i'            =>    'I',
                        'ii'        =>    'II',
                        'II'        =>    'II',
                        'iii'        =>    'III',
                        'III'        =>    'III',
                        'iv'        =>    'IV',
                        'IV'        =>    'IV',
                        'v'            =>    'V',
                        'V'            =>    'V',
                        'VI'        =>    'VI',
                        'vii'        =>    'VII',
                        'VII'        =>    'VII',
                        'viii'        =>    'VIII',
                        'VIII'        =>    'VIII'
                    );
                    $cons_data = $_POST;
                    if(isset($_POST['redirect_url']))
                        $this->redirect_url = $_POST['redirect_url'];
                        unset($cons_data['redirect_url']);
                    if(isset($cons_data['ngp_volunteer'])) {
                        unset($cons_data['ngp_volunteer']);
                    }
                    if(isset($cons_data['_wp_http_referer'])) {
                        unset($cons_data['_wp_http_referer']);
                    }
                    if(isset($_POST['FullName']) && !empty($_POST['FullName'])) {
                        // Split Name
                        $names = explode(' ', $_POST['FullName']);
                        unset($cons_data['FullName']);
                        foreach($names as $key => $value) {
                            $chars = "\t\n\r\0\x0B,.[]{};:\"'\x00..\x1F";
                            $names[$key] = trim($value, $chars);
                        }
                        if(count($names)==1) {
                            $cons_data['lastName'] = $names[0];
                        } else if(count($names)==2) {
                            $cons_data['firstName'] = $names[0];
                            $cons_data['lastName'] = $names[1];
                        } else if(count($names)>2) {
                            // Check for Prefix
                            $names[0] = str_replace('.', '', trim($names[0]));
                            foreach($namePrefix as $namePrefix) {
                                if(strlen($names[0])==strlen($namePrefix) || stripos($names[0], $namePrefix)!==false && isset($names[0])) {
                                    $names['prefix'] = $namePrefix;
                                    unset($names[0]);
                                }
                            }
                            
                            // Check for Suffix
                            foreach($nameSuffixes as $nameSuffix) {
                                $possible_suffix = null;
                                foreach($names as $k => $v) {
                                    if(is_int($k)) {
                                        $possible_skey = $k;
                                        $possible_suffix = $v;
                                    }
                                    $key = trim($key);
                                    if(strlen($possible_suffix)==strlen($key) && stripos($possible_suffix, $key)!==false) {
                                        $the_names['suffix'] = $value;
                                        unset($names[$possible_skey]);
                                    }
                                }
                            }
                            
                            $names = array_merge($names);
                            if(count($names)==1) {
                                $cons_data['lastName'] = $names[0];
                            } else if(count($names)==2) {
                                $cons_data['firstName'] = $names[0];
                                $cons_data['lastName'] = $names[1];
                            } else if(count($names)==3) {
                                $cons_data['firstName'] = $names[0];
                                $cons_data['MiddleName'] = $names[1];
                                $cons_data['lastName'] = $names[2];
                            } else if(count($names)==4) {
                                $cons_data['firstName'] = $names[0];
                                $cons_data['lastName'] = $names[3];
                            } else {
                                // Otherwise, let's bail out but save everything
                                $cons_data['firstName'] = $names[0];
                                foreach($names as $namekey => $name) {
                                    if($namekey==0) {
                                        $cons_data['firstName'] = $name;
                                    } else {
                                        if(!isset($cons_data['lastName'])) {
                                            $cons_data['lastName'] = $name;
                                        } else {
                                            $cons_data['lastName'] .= ' '.$name;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    require_once(dirname(__FILE__).'/NgpVolunteer.php');
                    $volunteer = new NgpVolunteer($this->api_key, $cons_data);
                    if($volunteer->save()) {
                        // Success!
                        // Redirect.
                        $_POST = array();
                        $this->been_processed = true;
                        // require_once(dirname(dirname(dirname(__FILE__))).'/wp-includes/pluggable.php');
                        header('Location: '.$this->redirect_url);
                        exit;
                    } else {
                        // Failure.
                        $this->ngp_error = true;
                    }
                }
            } else if(!empty($_POST) && isset($_POST['ngp_volunteer']) && !wp_verify_nonce($_POST['ngp_volunteer'], 'ngp_nonce_field')) {
                $this->ngp_error = true;
            }
            /* else if(!empty($_POST) && $_POST['ngp_form_id']!=$id) {
                $this->ngp_error = true;
            } */
            $this->been_processed = true;
        }
    }
    
    /**
     * Shows form used to donate
     */
    function show_form( $atts=null, $form=true ) {
        global $wpdb, $ngp;
        
        extract( shortcode_atts( array(
            'thanks_url' => null
        ), $atts ) );
        
        if($thanks_url!==null) {
            $this->redirect_url = $thanks_url;
        }
        // $this->main_code = $main_code;
        // $this->campaign_id = $campaign_id;
        
        $check_config = $this->check_config();
        
        if($check_config!==true) {
            return false;
            exit();
        }
        
        if(isset($fields) && !empty($fields)) {
            $fields = explode('|', $fields);
            $final_fields = array();
            foreach($fields as $field) {
                if(in_array($field, array('FullName', 'Zip', 'Email', 'Phone')))
                    $final_fields[] = $field;
            }
        }
        
        if(!empty($_POST)) {
            $this->process_form();
        }
        
        $form_fields = '';
        
        // Loop through and generate the elements
        foreach($this->fields as $field_key => $field) {
            if(!isset($final_fields) || in_array($field['name'], $final_fields)) {
                switch($field['type']) {
                    case 'text':
                        if(!isset($field['show_pre_div']) || $field['show_pre_div']=='true') {
                            $form_fields .= '
                                <div class="input';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error';
                            }
                            $form_fields .= '">';
                        }
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= '<div class="errMsg">This field cannot be left blank.</div>';
                        }
                        if(!isset($field['show_label']) || $field['show_label']!='false') {
                            $form_fields .= '
                                    <label for="'.$field['slug'].'">'.$field['label'];
                            if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                            $form_fields .= '</label>';
                        }
                        $form_fields .= '<input type="text" name="'.$field['slug'].'" id="'.$field['slug'].'" value="';
                        if(isset($_POST[$field['slug']])) {
                            $form_fields .= $_POST[$field['slug']];
                        }
                        $form_fields .= '"';
                        if(!empty($field['label']) && (!isset($field['show_placeholder']) || $field['show_placeholder']=='true')) {
                            $form_fields .= ' placeholder="'.$field['label'].'"';
                        }
                        $form_fields .= ' />';
                        if(!isset($field['show_post_div']) || $field['show_post_div']=='true') {
                            $form_fields .= '</div>';
                        }
                        break;
                    case 'file':
                        $file = true;
                        $form_fields .= '
                            <div class="file';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error';
                            }
                            $form_fields .= '">';
                            if(isset($field['error']) && $field['error']===true && $field['required']=='true') {
                                $form_fields .= '<div class="errMsg">You must provide a '.$field['label'].'.</div>';
                            } else if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= '<div class="errMsg">There was a problem uploading your file.</div>';
                            }
                    
                            $form_fields .= '
                                    <label for="'.$field['slug'].'">'.$field['label'];
                            if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                            $form_fields .= '</label>
                                <input type="file" name="'.$field['slug'].'" id="'.$field['slug'].'" />
                            </div>
                        ';
                        break;
                    case 'hidden':
                        $form_fields .= '<input type="hidden" name="'.$field['slug'].'" id="'.$field['slug'].'" value="';
                        if(isset($_POST[$field['slug']])) {
                            $form_fields .= $_POST[$field['slug']];
                        } else if(isset($field['value'])) {
                            $form_fields .= $field['value'];
                        }
                        $form_fields .= '" />';
                        break;
                    case 'password':
                        $form_fields .= '
                        <div class="password    ';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error';
                            }
                            $form_fields .= '">';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= '<div class="errMsg">This field cannot be left blank.</div>';
                            }
                            $form_fields .= '
                                    <label for="'.$field['slug'].'">'.$field['label'];
                            if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                            $form_fields .= '</label>
                        <input type="password" name="'.$field['slug'].'" id="'.$field['slug'].'" value="';
                        if(isset($_POST[$field['slug']])) {
                            $form_fields .= $_POST[$field['slug']];
                        }
                        $form_fields .= '"/>
                        </div>
                        ';
                        break;
                    case 'textarea':
                        $form_fields .= '
                        <div class="textarea';
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= ' error';
                        }
                        $form_fields .= '">';
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= '<div class="errMsg">This field cannot be left blank.</div>';
                        }
                        $form_fields .= '
                                <label for="'.$field['slug'].'">'.$field['label'];
                        if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                        $form_fields .= '</label>
                        <textarea name="'.$field['slug'].'" id="'.$field['slug'].'">';
                        if(isset($_POST[$field['slug']])) {
                            $form_fields .= $_POST[$field['slug']];
                        }
                        $form_fields .= '</textarea>
                        </div>
                        ';
                        break;
                    case 'checkbox':
                        if(isset($field['options']) && !empty($field['options'])) {
                            $form_fields .= '<fieldset id="ngp_'.$field['slug'].'" class="checkboxgroup';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error">
                                <div class="errMsg">You must check at least one.</div>';
                            } else {
                                $form_fields .= '">';
                            }
                            $form_fields .= '<legend>'.$field['label'];
                            if($field['required']=='true') $form_fields .= '<span class="required">*</span>';
                            $form_fields .= '</legend>';
                            $i = 0;
                            foreach($field['options'] as $val) {
                                $i++;
                                $form_fields .= '<div class="checkboxoption"><input type="checkbox" value="'.$val.'" name="'.$field['slug'].'['.$i.']['.$val.']" id="option_'.$i.'_'.$field['slug'].'" class="'.$field['slug'].'" /> <label for="option_'.$i.'_'.$field['slug'].'">'.$val.'</label></div>'."\r\n";
                            }
                            $form_fields .= '</fieldset>';
                        } else {
                            $form_fields .= '<div id="ngp_'.$field['slug'].'" class="checkbox">';
                            $form_fields .= '<div class="checkboxoption"><input type="checkbox" name="'.$field['slug'].'" id="'.$field['slug'].'" class="'.$field['slug'].'" /> <label for="'.$field['slug'].'">'.$field['label'].'</label></div>'."\r\n";
                            $form_fields .= '</div>';
                        }
                        break;
                    case 'radio':
                        $form_fields .= '
                        <fieldset id="ngp_'.$field['slug'].'" class="radiogroup';
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= ' error';
                        }
                        $form_fields .= '"><legend>'.$field['label'];
                        if($field['required']=='true') { $form_fields .= '<span class="required">*</span>'; }
                        $form_fields .= '</legend>';
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= '<div class="errMsg">You must select an option.</div>';
                        }
                        $i = 0;
                        foreach($field['options'] as $val => $labe) {
                            $i++;
                            if($val=='custom') {
                                $form_fields .= '<div class="radio custom-donation-amt">'.$labe.'</div>'."\r\n";
                            } else {
                                $form_fields .= '<div class="radio"><input type="radio" value="'.$val.'" name="'.$field['slug'].'" id="'.$i.'_'.$field['slug'].'" class="'.$field['slug'].'"> <label for="'.$i.'_'.$field['slug'].'">'.$labe.'</label></div>'."\r\n";
                            }
                        }
                        $form_fields .= '</fieldset>';
                        break;
                    case 'select':
                        if(!isset($field['show_pre_div']) || $field['show_pre_div']=='true') {
                            $form_fields .= '
                                <div class="input';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error';
                            }
                            $form_fields .= '">';
                        }
                        if(isset($field['error']) && $field['error']===true) {
                            $form_fields .= '<div class="errMsg">You must select an option.</div>';
                        }
                        if(!isset($field['show_label']) || $field['show_label']!='false') {
                            $form_fields .= '
                                    <label for="'.$field['slug'].'">'.$field['label'];
                            if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                            $form_fields .= '</label>';
                        }
                        $form_fields .= '<select name="'.$field['slug'].'" id="'.$field['slug'].'">'."\r\n";
                        if($field['slug']!='State' && $field['slug']!='ExpYear' && $field['slug']!='ExpMonth') {
                            $form_fields .= '
                            <option>Select an option...</option>
                            ';
                        }
                        foreach($field['options'] as $key => $val) {
                            $form_fields .= '<option value="'.$key.'"';
                            if(isset($default_state) && $default_state==$key) {
                                $form_fields .= ' selected="selected"';
                            }
                            $form_fields .= '>'.$val.'</option>'."\r\n";
                        }
                        $form_fields .= '</select>';
                        if(!isset($field['show_post_div']) || $field['show_post_div']=='true') {
                            $form_fields .= '</div>';
                        }
                        break;
                    case 'multiselect':
                        $form_fields .= '
                        <div class="multiselect    ';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= ' error';
                            }
                            $form_fields .= '">';
                            if(isset($field['error']) && $field['error']===true) {
                                $form_fields .= '<div class="errMsg">This field cannot be left blank.</div>';
                            }
                            $form_fields .= '
                                    <label for="'.$field['slug'].'">'.$field['label'];
                            if($field['required']=='true') { $form_fields .= ' <span class="required">*</span>'; }
                            $form_fields .= '</label>
                            <select multiple name="'.$field['slug'].'" id="'.$field['slug'].'">'."\r\n";
                                foreach($field['options'] as $key => $val) {
                                    $form_fields .= '<option value="'.$key.'">'.$val.'</option>'."\r\n";
                                }
                                $form_fields .= '
                            </select>
                        </div>
                        ';
                        break;
                }
            }
        }
        
        if($this->any_errors) {
            echo '<div class="errMsg ngp_alert">There were errors in your volunteer information! Please fix below and try again';
            if(!empty($this->support_phone)) {
                echo ' or call '.$this->support_phone;
            }
            echo '.</div>';
        } else if($this->ngp_error) {
            echo '<div class="errMsg ngp_alert">Sorry, but your submission to volunteer could not be processed. Please try again';
            if(!empty($this->support_phone)) {
                echo ' or call '.$this->support_phone;
            }
            echo '.</div>';
        }
        
        $return = '';
        if(!empty($form_fields)) {
            $return .= '<form name="ngp_user_news" class="ngp_user_submission" id="ngp_volunteer_form" action="'.$_SERVER['REQUEST_URI'].'" method="post">';
                if(function_exists('wp_nonce_field')) {
                    $return .= wp_nonce_field('ngp_nonce_field', 'ngp_volunteer', true, false);
                }
                $return .= $form_fields;
                if($thanks_url) {
                    $return .= '<input type="hidden" name="redirect_url" value="'.$thanks_url.'" />';
                }
                $return .= '<div class="submit">
                    <input type="submit" value="volunteer!" />
                </div>
            </form>';
        }
        return $return;
    }
}
$ngpVolunteerFrontend = new NGPVolunteerFrontend();

function ngp_process_volunteer() {
    global $ngpVolunteerFrontend;
    $ngpVolunteerFrontend->process_form();
}

function ngp_show_volunteer($atts=null, $form=true) {
    global $ngpVolunteerFrontend;
    return $ngpVolunteerFrontend->show_form($atts=null, $form=true);
}