<?PHP
/*
Simfatic Forms Main Form processor script

This script does all the server side processing. 
(Displaying the form, processing form submissions,
displaying errors, making CAPTCHA image, and so on.) 

All pages (including the form page) are displayed using 
templates in the 'templ' sub folder. 

The overall structure is that of a list of modules. Depending on the 
arguments (POST/GET) passed to the script, the modules process in sequence. 

Please note that just appending  a header and footer to this script won't work.
To embed the form, use the 'Copy & Paste' code in the 'Take the Code' page. 
To extend the functionality, see 'Extension Modules' in the help.

*/

@ini_set("display_errors", 1);//the error handler is added later in FormProc
error_reporting(E_ALL);

require_once(dirname(__FILE__)."/includes/Order_Request_Form-lib.php");
$formproc_obj =  new SFM_FormProcessor('Order_Request_Form');
$formproc_obj->initTimeZone('default');
$formproc_obj->setFormID('4d470475-941d-4b45-8c53-639bf8fe13af');
$formproc_obj->setRandKey('b10ad43e-f8f7-4e29-ae49-dfbcb5b4b868');
$formproc_obj->setFormKey('bb6c4065-6164-4de5-b66a-16947150a1b7');
$formproc_obj->setLocale('en-US','M/d/yyyy');
$formproc_obj->setEmailFormatHTML(true);
$formproc_obj->EnableLogging(false);
$formproc_obj->SetDebugMode(false);
$formproc_obj->setIsInstalled(true);
$formproc_obj->SetPrintPreviewPage(sfm_readfile(dirname(__FILE__)."/templ/Order_Request_Form_print_preview_file.txt"));
$formproc_obj->SetSingleBoxErrorDisplay(true);
$formproc_obj->setFormPage(0,sfm_readfile(dirname(__FILE__)."/templ/Order_Request_Form_form_page_0.txt"));
$formproc_obj->AddElementInfo('Name','text','');
$formproc_obj->AddElementInfo('Email','text','');
$formproc_obj->AddElementInfo('Custom_Order_Options','chk_group','');
$formproc_obj->AddElementInfo('Other','single_chk','');
$formproc_obj->AddElementInfo('Multiline','multiline','');
$formproc_obj->SetHiddenInputTrapVarName('t057fcf48e7463d0ab423');
$formproc_obj->SetFromAddress('Digital Design Duo <digitaldesignduo02@gmail.com>');
$page_renderer =  new FM_FormPageDisplayModule();
$formproc_obj->addModule($page_renderer);

$data_email_sender =  new FM_FormDataSender(sfm_readfile(dirname(__FILE__)."/templ/Order_Request_Form_email_subj.txt"),sfm_readfile(dirname(__FILE__)."/templ/Order_Request_Form_email_body.txt"),'%Email%');
$data_email_sender->AddToAddr('Digital Design Duo <digitaldesignduo02@gmail.com>');
$formproc_obj->addModule($data_email_sender);

$tupage =  new FM_ThankYouPage(sfm_readfile(dirname(__FILE__)."/templ/Order_Request_Form_thank_u.txt"));
$formproc_obj->addModule($tupage);

$formproc_obj->ProcessForm();

?>