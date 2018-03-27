<?php

/**
 * UserAdmin is a MediaWiki extension which allows administrators to add users, 
 * permanently remove spam or unused accounts, change user passwords, edit user 
 * details, send reset password or welcome emails and list users with pagination 
 * and filter controls. This extension is primarily for administrators of 
 * private wikis that require tighter control of user accounts.
 *
 * Usage:
 * 	require_once("$IP/extensions/UserAdmin/UserAdmin.php"); in LocalSettings.php
 *
 * @file
 * @ingroup Extensions
 * @link http://www.mediawiki.org/wiki/Extension:UserAdmin   Documentation
 * @author Lance Gatlin <lance.gatlin@gmail.com>
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License 3.0
 * @version 0.9.0
*/

/*
 * Special page to edit a user
 */
class SpecialEditUser extends SpecialUADMBase {

  function __construct() {
    parent::__construct('EditUser', 'createaccount');
  }
  
  /*
   * Get the parameters and their default values for a GET
   * 
   * @return array key-value parameters with default value
   */
  function getParamsGET()
  {
    return array(
      'userid' => '',
      'username' => '',
      'domain' => 'local',
      'realname' => '',
      'groups' => array(),
      'pwdaction' => 'nochange',
      'email' => '',
      'reason' => '',
      'returnto' => $this->getDefaultReturnTo(),
      'preview' => '',
    );
  }
  
  /*
   * Get the parameters and their default values for a POST
   * 
   * @return array key-value parameters with default value
   */
  function getParamsPOST()
  {
    // Have to list these here otherwise they will never get read
    // from wgRequest
    return array(
      'action' => '',
      'userid' => '',
      'username' => '',
      'domain' => 'local',
      'realname' => '',
      'email' => '',
      'groups' => array(),
      'pwdaction' => '',
      'password1' => '',
      'password2' => '',
      'reason' => '',
      'edittoken' => '',
      'returnto' => $this->getDefaultReturnTo(),
    );
  }

  /*
   * Helper function to validate get parameters; throws on invalid
   * 
   * @return User object requested for edit based on parameters OR null
   * if user not found
   */
  function validateGETParams()
  {
    if(!empty($this->returnto))
    {
      $title = Title::newFromText($this->returnto);
      if(!is_object($title) || !$title->isKnown())
        $this->returnto = $this->mDefaultParams['returnto'];
    }
    
    if(!empty($this->userid))
    {
      $user = User::newFromId($this->userid);
      if(!$user->loadFromId())
        throw new InvalidGETParamException(wfMessage('uadm-invaliduseridmsg',$this->userid)->text(), $this->copyParamsAndRemoveBadParam('userid'));
      
      return $user;
    }
    
    if(!empty($this->subpage))
    {
      $user = User::newFromName($this->subpage);
      if(!is_object($user) || $user->getId() == 0)
        throw new InvalidGETParamException(wfMessage('uadm-usernoexistmsg', $this->subpage)->text(), $this->copyParamsAndRemoveBadParam('subpage'));
      return $user;
    }
    
    if(!empty($this->username))
    {
      $user = User::newFromName($this->username);
      if(!is_object($user) || $user->getId() == 0)
        throw new InvalidGETParamException(wfMessage('uadm-usernoexistmsg', $this->username)->text(), $this->copyParamsAndRemoveBadParam('username'));
      return $user;
    }
    
    return null;
  }
  
  /*
   * Display the edit user form
   * 
   * @return string HTML
   */
  function doGET() 
  {
    global $wgLang, $wgOut, $wgUser, $wgAuth;
    
    $user = $this->validateGETParams();
    
    $searchFormHTML = $this->getSearchFormHTML(wfMessage('uadm-finduserlabel')->text());
    
    $returnToHTML = '';
    $backHTML = '';
    if(!empty($this->returnto))
    {
      $returnToHTML = self::parse(wfMessage('uadm-returntomsg', $this->returnto)->text());
      $backHTML = $this->parse("[[$this->returnto|< ".wfMessage( 'uadm-backactionlabel' )->text()."]] | ");
    }
    
    if(!is_object($user))
      return <<<EOT
$searchFormHTML
$returnToHTML
EOT;
    
    // Suppress search form if editing a user and returnto specified 
    if(!empty($this->returnto))
      $searchFormHTML = '';
    
    $id = $user->getId();
    $this->userid = $id;
    $this->mParams['userid'] = $id;

    // user editable parameters
    $userName = $user->getName();
    $realName = $user->getRealName();
    $email = $user->getEmail();
    $groups = $user->getGroups();
    
    // If userid was used to load the user then prefer URL
    // query parameters to preserve unsaved changes since preview 
    // POST was selected then redirected back to GET
    if(!empty($this->userid))
    {
      if(!empty($this->username)) $userName = ($this->username = strtoupper(substr($this->username,0,1)) . substr($this->username,1)); // db stores first character as uppercase
      if(!empty($this->realname)) $realName = $this->realname;
      if(!empty($this->email)) $email = $this->email;
      if(!empty($this->groups)) $groups = $this->groups;
    }
    
    $emailAuthDate = $user->getEmailAuthenticationTimestamp();
    
    $groupsHTML = '';
    foreach(User::getAllGroups() as $groupName)
    {
      $checked = in_array($groupName, $groups) ? 'checked' : '';
      $localName = User::getGroupMember($groupName);
      $groupsHTML.= <<<EOT
<input id="grp$groupName" type="checkbox" name="groups[]" value="$groupName" $checked/> <label for="grp$groupName">$localName</label><br/>
EOT;
    }

    $unconfirmed = $user->isEmailConfirmationPending() ? '[' . $this->pendinglabel . ']' : '';
    $userPageURL = $user->getUserPage()->getLocalURL();
    $editCount = $user->getEditCount();
    $userTouchedDate = $user->getTouched();
    $userTouchedDate = $wgLang->timeanddate($userTouchedDate, true);
    $createDate = $user->getRegistration();
    $createDate = $wgLang->timeanddate($createDate, true);
    if ($editCount > 0) {
      $lastEditDate = self::getUserLastEditTimestamp($user);
      $lastEditDate = $wgLang->timeanddate($lastEditDate, true);
    }
    else
      $lastEditDate = '';

    $contribsHref = $this->getSpecialPageURL('Contributions', $userName);
    $logsHref = $this->getSpecialPageURL('Log',$userName);
    $groupsHref = $this->getSpecialPageURL('UserRights',$userName);
    
    $userPageHref = $user->getUserPage()->getLocalURL();
    $userTalkPageHref = $user->getUserPage()->getTalkPage()->getLocalURL();
    $blockHref = $this->getSpecialPageURL('Block',$userName);
    $purgeHref = $this->getSpecialPageURL('PurgeUser',$userName);
    $logsHref = $this->getSpecialPageURL('Log',$userName);
    $checkuserHref = $this->getSpecialPageURL('CheckUser',$userName);
    
    $pwdtitleHref = Title::newFromText('passwordremindertitle', NS_MEDIAWIKI)->getLocalURL();
    $pwdtextHref = Title::newFromText('passwordremindertext', NS_MEDIAWIKI)->getLocalURL();
    $welcomeTitleHref = Title::newFromText('createaccount-title', NS_MEDIAWIKI)->getLocalURL();
    $welcomeTextHref = Title::newFromText('createaccount-text', NS_MEDIAWIKI)->getLocalURL();
    
    $returnToHTML = '';
    $backHTML = '';
    if(!empty($this->returnto))
    {
      $returnToHTML = self::parse(wfMessage('uadm-returntomsg', $this->returnto)->text());
      $searchFormHTML = '';
      $backHTML = $this->parse("[[$this->returnto|< ".wfMessage( 'uadm-backactionlabel' )->text()."]] | ");
    }
    
    $postURL = $this->getURL($this->mParams);
    
    $editToken = $wgUser->getEditToken($this->userid);

//    $previewPasswordEmailHref = $this->getURL(array('preview' => 'password') + $this->mParams);
//    $previewWelcomeEmailHref = $this->getURL(array('preview' => 'welcome') + $this->mParams);
    
    $previewPasswordEmailHTML = '';
    $previewWelcomeEmailHTML = '';
    if(!empty($this->preview))
    {
      switch($this->preview)
      {
        case 'password' :
          list($subject, $body) = $this->getPasswordMailMessage($user);
          break;
        case 'welcome' :
          list($subject, $body) = $this->getWelcomeMailMessage($user);
          break;
      }
    
      $previewHTML=<<<EOT
<table>
  <tr>
    <td>$this->subjectlabel</td>
    <td><input value="$subject" size="70" disabled="disabled"/></td>
  <tr>
    <td colspan="2"><textarea rows="10" cols="80" disabled="disabled">$body</textarea></td>
  </tr>
</table>
EOT;
    
      switch($this->preview)
      {
        case 'password' :
          $previewPasswordEmailHTML = $previewHTML;
          break;
        case 'welcome' :
          $previewWelcomeEmailHTML = $previewHTML;
          break;
      }
    }
    
    $pwdSetPasswordChecked = '';
    $pwdEmailPasswordChecked = '';
    $pwdEmailWelcomeChecked = '';
    $pwdNoChangeChecked = '';
    switch($this->pwdaction)
    {
      case 'manual' :
        $pwdSetPasswordChecked = 'checked';
        break;
      case 'email' :
        $pwdEmailPasswordChecked = 'checked';
        break;
      case 'emailwelcome' :
        $pwdEmailWelcomeChecked = 'checked';
        break;
      case 'nochange' :
        $pwdNoChangeChecked = 'checked';
        break;
    }
    
    $talkactionlabelHTML = wfMessage('uadm-talkactionlabel')->text();
    $blockactionlabelHTML = wfMessage('uadm-blockactionlabel')->text();
    $purgeactionlabelHTML = wfMessage('uadm-purgeactionlabel')->text();
    $logsactionlabelHTML = wfMessage('uadm-logsactionlabel')->text();
    $contributionsactionlabelHTML = wfMessage('uadm-contributionsactionlabel')->text();
    $ipsactionlabelHTML = wfMessage('uadm-psactionlabel')->text();
    
    $subtitle =<<<EOT
$backHTML<a href="$userPageHref"><b>$userName</b></a> (<a href="$userTalkPageHref">$talkactionlabelHTML</a> | <a href="$blockHref">$blockactionlabelHTML</a> | <a href="$purgeHref">$purgeactionlabelHTML</a> | <a href="$logsHref">$logsactionlabelHTML</a> | <a href="$contribsHref">$contributionsactionlabelHTML</a> | <a href="$checkuserHref">$ipsactionlabelHTML</a>) 
EOT;
    
    $wgOut->setSubtitle($subtitle);
    
    # Hack to detect if domain is needed
    $domainHTML = '';
    # $template = new UsercreateTemplate;
    $template = null; # TODO: to be fixed
    $temp = 'signup';
    // Bug fix. This does nothing.
    $wgAuth->autoCreate(); 
    // The first time wgAuth is called, some PHP auto-magic involving StubObject
    // occurs to "unstub" wgAuth AND call the function invoked. If the below
    // call is made as written, the call is actually made by calling 
    // call_user_func_array and the arguments are passed by value even though
    // the modifyUITemplate expects them to be by reference.
    // This use to be a non issue since call-time pass-by-reference was allowed
    // $wgAuth->modifyUITemplate(&$template, &$temp); 
    // This generates warnings now. Solution is to perform a no-op call to
    // wgAuth to "unstub" it so that the below call will be made directly and
    // not by call_user_func_array
    $wgAuth->modifyUITemplate($template, $temp);
    if(isset($template->data['usedomain']) && $template->data['usedomain'] == true)
    {
      $domainHTML = <<<EOT
      <tr>
        <td><label for="domain">$this->domainfield</label></td>
        <td><input id="domain" type="text" name="domain" size="30" value="$this->domain"/><br/></td>
      </tr>
EOT;
    }
    
    $edituserlabelHTML = wfMessage( 'uadm-edituserlabel' )->text();
    $useridfieldHTML = wfMessage( 'uadm-useridfield' )->text();
    $usernamefieldHTML = wfMessage( 'uadm-usernamefield' )->text();
    $requiredlabelHTML = wfMessage( 'uadm-requiredlabel')->text();
    $realnamefieldHTML = wfMessage( 'uadm-realnamefield' )->text();
    $emailfieldHTML = wfMessage( 'uadm-emailfield' )->text();
    $emailauthdatefieldHTML = wfMessage( 'uadm-emailauthdatefield' )->text();
    $createddatefieldHTML = wfMessage('uadm-createddatefield')->text();
    $usertoucheddatefieldHTML = wfMessage('uadm-usertoucheddatefield')->text();
    $editcountfieldHTML = wfMessage('uadm-editcountfield')->text();
    $lasteditdatefieldHTML = wfMessage('uadm-lasteditdatefield')->text();
    $editgroupslabelHTML = wfMessage( 'uadm-editgroupslabel' )->text();
    $editpasswordlabelHTML = wfMessage( 'uadm-editpasswordlabel' )->text();
    $setpasswordforuserlabelHTML = wfMessage( 'uadm-setpasswordforuserlabel' )->text();
    $passwordlabelHTML = wfMessage( 'uadm-passwordlabel' )->text();
    $verifypasswordlabelHTML = wfMessage( 'uadm-verifypasswordlabel' )->text();
    $emailpasswordlabelHTML = wfMessage( 'uadm-emailpasswordlabel' )->text();
    $emailwelcomelabelHTML = wfMessage( 'uadm-emailwelcomelabel' )->text();
    $previewactionlabelHTML = wfMessage( 'uadm-previewactionlabel')->text();
    $subjectlabelHTML = wfMessage( 'uadm-subjectlabel' )->text();
    $bodylabelHTML = wfMessage( 'uadm-bodylabel' )->text();
    $nochangetopasswordlabelHTML = wfMessage( 'uadm-nochangetopasswordlabel' )->text();
    $reasonlabelHTML = wfMessage( 'uadm-reasonlabel' )->text();
    $saveuserlabelHTML = wfMessage( 'uadm-saveuserlabel' )->text();

    return <<<EOT
<form id="edituserform" name="input" action="$postURL" method="post" class="visualClear">
  <input type="hidden" name="edittoken" value="$editToken"/>
  <fieldset>
    <legend>$edituserlabelHTML:</legend>
    <table>
      <tr>
        <td><label for="userid">$useridfieldHTML:</label></td>
        <td><input id="userid" type="text" name="userid" value="$id" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="username">$usernamefieldHTML:</label></td>
        <td><input id="username" type="text" name="username" value="$userName" size="30"/> $requiredlabelHTML<br/></td>
      </tr>
$domainHTML
      <tr>
        <td><label for="realname">$realnamefieldHTML:</label></td>
        <td><input id="realname" type="text" name="realname" value="$realName" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="email">$emailfieldHTML:</label></td>
        <td><input id="email" type="text" name="email" value="$email" size="30"/> $requiredlabelHTML<br/></td>
      </tr>
      <tr>
        <td><label for="emailauthdate">$emailauthdatefieldHTML:</label></td>
        <td><input id="emailauthdate" type="text" value="$emailAuthDate" size="30" disabled="disabled"/><br/></td>
      </tr>
      <tr>
        <td><label for="createdate">$createddatefieldHTML:</label></td>
        <td><input id="createdate" type="text" value="$createDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="usertouched">$usertoucheddatefieldHTML:</label></td>
        <td><input id="usertouched" type="text" value="$userTouchedDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="lasteditdate">$lasteditdatefieldHTML:</label></td>
        <td><input id="lasteditdate" type="text" value="$lastEditDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="editcount">$editcountfieldHTML:</label></td>
        <td><input id="editcount" type="text" value="$editCount" disabled="disabled" size="30"/><br/></td>
      </tr>
    </table>
    <fieldset>
      <legend>$editgroupslabelHTML:</legend>
      $groupsHTML
    </fieldset>
    <fieldset>
      <legend>$editpasswordlabelHTML:</legend>
      <input id="pwdmanual" type="radio" name="pwdaction" value="manual" $pwdSetPasswordChecked/> <label for="pwdmanual">$setpasswordforuserlabelHTML:</label><br/>
        <table>
          <tr>
            <td><label for="password1">$passwordlabelHTML:</label></td>
            <td><input id="password1" type="password" name="password1" size="30"/></td>
          </tr>
          <tr>
            <td><label for="password2">$verifypasswordlabelHTML:</label></td>
            <td><input id="password2" type="password" name="password2" size="30"/></td>
          </tr>
        </table>
      <input id="pwdemail" type="radio" name="pwdaction" value="email" $pwdEmailPasswordChecked/> <label for="pwdemail">$emailpasswordlabelHTML</label> <button type="submit" name="action" value="emailpwdpreview">$previewactionlabelHTML</button>(<a href="$pwdtitleHref">$subjectlabelHTML</a> | <a href="$pwdtextHref">$bodylabelHTML</a>)<br/>
      $previewPasswordEmailHTML
      <input id="pwdemailwelcome" type="radio" name="pwdaction" value="emailwelcome" $pwdEmailWelcomeChecked/> <label for="pwdemailwelcome">$emailwelcomelabelHTML</label> <button type="submit" name="action" value="emailwelcomepreview">$previewactionlabelHTML</button>(<a href="$welcomeTitleHref">$subjectlabelHTML</a> | <a href="$welcomeTextHref">$bodylabelHTML</a>)<br/>
      $previewWelcomeEmailHTML
      <input id="pwdnochange" type="radio" name="pwdaction" value="nochange" $pwdNoChangeChecked/> <label for="pwdnochange">$nochangetopasswordlabelHTML</label><br/>
    </fieldset>
    <label for="reason">$reasonlabelHTML:</label> <input id="reason" type="text" name="reason" size="60" maxlength="255" value="$this->reason"/> $requiredlabelHTML<br/>
    <button type="submit" name="action" value="saveuser">$saveuserlabelHTML</button>
  </fieldset>
</form>
$searchFormHTML
$returnToHTML
EOT;
    }

  /*
   * Helper function to validate POST parameters
   */
  function validatePOSTParams()
  {
    global $wgUser, $wgAuth;
    
    $user = User::newFromId($this->userid);
    if(!$user->loadFromId())
      throw new InvalidPOSTParamException(wfMessage('uadm-failedtoloadfromidmsg', $this->userid)->text());
    $this->username = strtoupper(substr($this->username,0,1)) . substr($this->username,1); // in case the user changes the case of the first character
    // Validate FORM 
    if(empty($this->username))
      throw new InvalidPOSTParamException(wfMessage('uadm-fieldisrequiredmsg',$this->usernamefield)->text());
    
    // changing user name?
    if($user->getName() != $this->username)
    {
      // check if its already being used
      if(User::idFromName($this->username) !== null)
        throw new InvalidPOSTParamException(wfMessage('uadm-usernameinusemsg', $this->username)->text());

      if(!User::isCreatableName($this->username))
        throw new InvalidPOSTParamException(wfMessage('uadm-invalidusernamemsg',$this->usernamefield)->text());

      if($this->domain != 'local' && $this->domain != '')
      {
        if(!$wgAuth->validDomain($this->domain))
          throw new InvalidPOSTParamException(wfMessage('uadm-invaliddomainmsg')->text());
        
        $wgAuth->setDomain($this->domain);
        
        if($wgAuth->userExists($this->username))
          throw new InvalidPOSTParamException(wfMessage('uadm-usernameinusemsg', $this->username)->text());
      }
    }
    
//    if(!$wgUser->matchEditToken(stripslashes($this->edittoken), $this->userid))
    if(!$wgUser->matchEditToken($this->edittoken, $this->userid))
      throw new InvalidPOSTParamException(wfMessage('uadm-formsubmissionerrormsg')->text());
    
    if(empty($this->email))
      throw new InvalidPOSTParamException(wfMessage('uadm-fieldisrequiredmsg',$this->emailfield)->text());

    if(!Sanitizer::validateEmail($this->email))
      throw new InvalidPOSTParamException(wfMessage('uadm-invalidemailmsg',$this->emailfield)->text());

    if(empty($this->reason))
      throw new InvalidPOSTParamException(wfMessage('uadm-fieldisrequiredmsg',$this->reasonfield)->text());
    
    if(empty($this->pwdaction))
      throw new InvalidPOSTParamException(wfMessage('uadm-formsubmissionerrormsg')->text());
      
    if($this->action == 'saveuser' && $this->pwdaction == 'manual')
    {
      if(empty($this->password1) || empty($this->password2))
        throw new InvalidPOSTParamException(wfMessage('uadm-fieldisrequiredmsg',$this->passwordfield)->text());
      
      if($this->password1 != $this->password2)
        throw new InvalidPOSTParamException(wfMessage('uadm-passwordsmustmatchmsg')->text());
      
//      $result = $user->checkPassword($this->password1);
//      if($result !== true)
//        throw new InvalidPOSTParamException(wfMessage('uadm-invalidpasswordmsg')->text());
    }
    
    return $user;
  }
  
  /*
   * Edit a user according to POST parameters
   * 
   * @return string URL to redirect to
   */
  function doPOST()
  {
    global $wgUser, $wgAuth;

    switch($this->action)
    {
      case 'emailpwdpreview' :
        return $this->getURL(array('preview' => 'password', 'pwdaction' => 'email') + $this->mParams);
      case 'emailwelcomepreview' :
        return $this->getURL(array('preview' => 'welcome', 'pwdaction' => 'emailwelcome') + $this->mParams);
      default :
        throw new InvalidPOSTParamException(wfMessage('uadm-formsubmissionerrormsg')->text());
      case 'saveuser' :
        break;
    }

    $user = $this->validatePOSTParams();
    
    $log = new LogPage( 'rights' );
    $changesMade = false;
    
    $userName = $user->getName();
    
    // Apply parameters that have changed
    if($user->getName() != $this->username)
    {
      $oldName = $user->getName();
      $user->setName($wgAuth->getCanonicalName($this->username));
      $newName = $user->getName();
      
      $log->addEntry( 
        'uadm-changedusernamelog',
        $user->getUserPage(),
        $this->reason,
        array(
          $this->userid,
          $oldName,
          $newName,
        )
      );

      $changesMade = true;
    }
    
    if($user->getRealName() != $this->realname)
    {
      $oldRealName = $user->getRealName();
      $user->setRealName($this->realname);      
      $newRealName = $user->getRealName();
      
      $log->addEntry( 
        'uadm-changeduserrealnamelog',
        $user->getUserPage(),
        $this->reason,
        array(
          $oldRealName,
          $newRealName,
        )
      );

      $changesMade = true;
    }
    
    if($user->getEmail() != $this->email)
    {
      $oldEmail = $user->getEmail();
      $user->setEmail($this->email);
      $newEmail = $user->getEmail();
      
      $log->addEntry( 
        'uadm-changeduseremaillog',
        $user->getUserPage(),
        $this->reason,
        array(
          $oldEmail,
          $newEmail,
        )
      );

      $changesMade = true;
    }
   
    $successWikiText = array();
    if($changesMade)
      $successWikiText[] = wfMessage('uadm-changestousersuccessmsg', $this->username)->text();
    
    switch($this->pwdaction)
    {
      case 'manual' :
        try {
          $user->setPassword($this->password1);
          $changesMade = true;
        }
        catch(PasswordError $pe)
        {
          return $this->getPOSTRedirectURL(false, wfMessage('uadm-passworderrormsg')->text() . $pe->getText());
        }
        $log->addEntry( 
          'uadm-changeduserpasswordlog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMessage('uadm-passwordchangesuccessmsg',$this->username)->text();
        break;
      
      case 'email' :
        $result = self::mailPassword($user);

        if( ! $result->isGood() )
          return $this->getPOSTRedirectURL(false, wfMessage( 'uadm-mailerrormsg', $result->getMessage() )->text() );

        $changesMade = true;
        
        $log->addEntry( 
          'uadm-emailpasswordlog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMessage('uadm-passwordemailsuccessmsg', $this->username, $this->email)->text();
        break;
        
      case 'emailwelcome' :
        $result = self::mailWelcomeAndPassword($user);

        if( ! $result->isGood() )
          return $this->getPOSTRedirectURL( false, wfMessage( 'uadm-mailerrormsg', $result->getMessage() )->text() );

        $changesMade = true;
        
        $log->addEntry( 
          'uadm-emailwelcomelog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMessage('uadm-welcomeemailsuccessmsg', $this->username, $this->email)->text();
        break;
    }
    
    if($changesMade)
    {
      if(!$wgAuth->updateExternalDB($user))
        return $this->getPOSTRedirectURL(false, wfMessage('uadm-externalupdateerrormsg')->text());
      
      $user->saveSettings();
    }
    
    # Update groups if needed
    $currentGroups = $user->getGroups();
    $remove = array();
    $add = array();
    foreach($currentGroups as $groupName)
    {
      if(!in_array($groupName, $this->groups))
        $remove[] = $groupName;
    }
    foreach($this->groups as $groupName)
    {
      if(!in_array($groupName, $currentGroups))
        $add[] = $groupName;
    }
    
    if(count($remove) > 0|| count($add) > 0)
    {
      $userrightsPage = new UserrightsPage;    
      $userrightsPage->doSaveUserGroups($user, $add, $remove, $this->reason);
      wfRunHooks( 'UserRights', array( $user, $add, $remove ) );
      $successWikiText[] = wfMessage('uadm-changestogroupsuccessmsg', $this->username)->text();
    }
    
    
    $successWikiText = implode('<br/>', $successWikiText);
    
    return $this->getPOSTRedirectURL(true, $successWikiText);
  }
}
