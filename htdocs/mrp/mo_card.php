<?php

/* Copyright (C) 2017-2020 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *    \file       htdocs/mrp/mo_card.php
 *    \ingroup    mrp
 *    \brief      Page to create/edit/view MO Manufacturing Order
 */


// Load Dolibarr environment
require '../main.inc.php';

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/lib/mrp_mo.lib.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/lib/bom.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
dol_include_once('/mrp/class/mo.class.php');


// Load translation files required by the page
$langs->loadLangs(array('mrp', 'other'));

$idsprod = array();

// Get parameters
$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'mocard'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');
$backtopageforcancel = GETPOST('backtopageforcancel', 'alpha');
$TBomLineId = GETPOST('bomlineid', 'array');
$lineid = GETPOST('lineid', 'int');
$socid = GETPOST("socid", 'int');

// Initialize technical objects
$object = new Mo($db);

$objectbom = new BOM($db);

$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->mrp->dir_output . '/temp/massgeneration/' . $user->id;
$hookmanager->initHooks(array('mocard', 'globalcard')); // Note that conf->hooks_modules contains array

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Initialize array of search criterias
$search_all = GETPOST("search_all", 'alpha');
$search = array();
foreach ($object->fields as $key => $val) {
    if (GETPOST('search_' . $key, 'alpha')) {
        $search[$key] = GETPOST('search_' . $key, 'alpha');
    }
}

if (empty($action) && empty($id) && empty($ref)) {
    $action = 'view';
}

// Load object
include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php'; // Must be include, not include_once.

if (GETPOST('fk_bom', 'int') > 0) {

    $objectbom->fetch(GETPOST('fk_bom', 'int'));
//    var_dump($objectbom->lines);

//    localStorage.setItem('bomLabel', $objectbom->label);
    if ($action != 'add') {
        // We force calling parameters if we are not in the submit of creation of MO
        $_POST['fk_product'] = $objectbom->fk_product;
        $_POST['qty'] = $objectbom->qty;
        $_POST['mrptype'] = $objectbom->bomtype;
        $_POST['fk_warehouse'] = $objectbom->fk_warehouse;
        $_POST['note_private'] = $objectbom->note_private;
    }
}

// Security check - Protection if external user
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
$result = restrictedArea($user, 'mrp', $object->id, 'mrp_mo', '', 'fk_soc', 'rowid', $isdraft);

// Permissions
$permissionnote = $user->rights->mrp->write; // Used by the include of actions_setnotes.inc.php
$permissiondellink = $user->rights->mrp->write; // Used by the include of actions_dellink.inc.php
$permissiontoadd = $user->rights->mrp->write; // Used by the include of actions_addupdatedelete.inc.php and actions_lineupdown.inc.php
$permissiontodelete = $user->rights->mrp->delete || ($permissiontoadd && isset($object->status) && $object->status == $object::STATUS_DRAFT);
$upload_dir = $conf->mrp->multidir_output[isset($object->entity) ? $object->entity : 1];


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    $error = 0;

    $backurlforlist = dol_buildpath('/mrp/mo_list.php', 1);

    $object->oldQty = $object->qty;

    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
            if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
                $backtopage = $backurlforlist;
            } else {
                $backtopage = DOL_URL_ROOT . '/mrp/mo_card.php?id=' . ($id > 0 ? $id : '__ID__');
            }
        }
    }
    if ($cancel && !empty($backtopageforcancel)) {
        $backtopage = $backtopageforcancel;
    }
    $triggermodname = 'MO_MODIFY'; // Name of trigger action code to execute when we modify record

    // Create MO with Childs
    if ($action == 'add' && empty($id) && !empty($TBomLineId)) {
        $noback = 1;
        include DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';

        $mo_parent = $object;

        $moline = new MoLine($db);
        $objectbomchildline = new BOMLine($db);

        foreach ($TBomLineId as $id_bom_line) {
            $object = new Mo($db);

            $objectbomchildline->fetch($id_bom_line);

            $TMoLines = $moline->fetchAll('DESC', 'rowid', '1', '', array('origin_id' => $id_bom_line));

            foreach ($TMoLines as $moline) {
                $_POST['fk_bom'] = $objectbomchildline->fk_bom_child;
                $_POST['fk_parent_line'] = $moline->id;
                $_POST['qty'] = $moline->qty;
                $_POST['fk_product'] = $moline->fk_product;
            }

            include DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';

            $res = $object->add_object_linked('mo', $mo_parent->id);
        }

        header("Location: " . dol_buildpath('/mrp/mo_card.php?id=' . ((int)$moline->fk_mo), 1));
        exit;
    }

    // Actions cancel, add, update, update_extras, confirm_validate, confirm_delete, confirm_deleteline, confirm_clone, confirm_close, confirm_setdraft, confirm_reopen
    include DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';

    // Actions when linking object each other
    include DOL_DOCUMENT_ROOT . '/core/actions_dellink.inc.php';

    // Actions when printing a doc from card
    include DOL_DOCUMENT_ROOT . '/core/actions_printing.inc.php';

    // Action to build doc
    include DOL_DOCUMENT_ROOT . '/core/actions_builddoc.inc.php';

    if ($action == 'set_thirdparty' && $permissiontoadd) {
        $object->setValueFrom('fk_soc', GETPOST('fk_soc', 'int'), '', '', 'date', '', $user, $triggermodname);
    }
    if ($action == 'classin' && $permissiontoadd) {
        $object->setProject(GETPOST('projectid', 'int'));
    }

    // Actions to send emails
    $triggersendname = 'MO_SENTBYMAIL';
    $autocopy = 'MAIN_MAIL_AUTOCOPY_MO_TO';
    $trackid = 'mo' . $object->id;
    include DOL_DOCUMENT_ROOT . '/core/actions_sendmails.inc.php';

    // Action to move up and down lines of object
    //include DOL_DOCUMENT_ROOT.'/core/actions_lineupdown.inc.php';	// Must be include, not include_once

    // Action close produced
    if ($action == 'confirm_produced' && $confirm == 'yes' && $permissiontoadd) {
        $result = $object->setStatut($object::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED');
        if ($result >= 0) {
            // Define output language
            if (empty($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE)) {
                $outputlangs = $langs;
                $newlang = '';
                if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang) && GETPOST('lang_id', 'aZ09')) {
                    $newlang = GETPOST('lang_id', 'aZ09');
                }
                if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang)) {
                    $newlang = $object->thirdparty->default_lang;
                }
                if (!empty($newlang)) {
                    $outputlangs = new Translate("", $conf);
                    $outputlangs->setDefaultLang($newlang);
                }
                $model = $object->model_pdf;
                $ret = $object->fetch($id); // Reload to get new records

                $object->generateDocument($model, $outputlangs, 0, 0, 0);
            }
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }
}


/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
$formproject = new FormProjets($db);

$title = $langs->trans('ManufacturingOrder') . " - " . $langs->trans("Card");

llxHeader('', $title, '');


// Part to create
if ($action == 'create') {
    if (GETPOST('fk_bom', 'int') > 0) {
        $titlelist = $langs->trans("ToConsume");
        if ($objectbom->bomtype == 1) {
            $titlelist = $langs->trans("ToObtain");
        }
    }

    print load_fiche_titre($langs->trans("NewObject", $langs->transnoentitiesnoconv("Mo")), '', 'mrp');

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" id="idsprd" name="idsprd" value="">';
    print '<input type="hidden" name="action" value="add">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }
    if ($backtopageforcancel) {
        print '<input type="hidden" name="backtopageforcancel" value="' . $backtopageforcancel . '">';
    }

    print dol_get_fiche_head(array(), '');

    print '<table class="border centpercent tableforfieldcreate">' . "\n";

    // Common attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_add.tpl.php';

    // Other attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_add.tpl.php';

    print '</table>' . "\n";

    print dol_get_fiche_end();

    mrpCollapseBomManagement();

    ?>
    <script>
        $(document).ready(function () {
            jQuery('#fk_bom').change(function () {
                console.log('We change value of BOM with BOM of id ' + jQuery('#fk_bom').val());
                if (jQuery('#fk_bom').val() > 0) {

                    Cookies.remove('DELSESSIDS_6489c7a8a26573c0');
                    Cookies.remove('DELSESSIDS_6489c7a8a26573c0Unchecked')
                    window.location.href = '<?php echo $_SERVER["PHP_SELF"] ?>?action=create&token=<?php echo newToken(); ?>&fk_bom=' + jQuery('#fk_bom').val();


                    /*
                    $.getJSON('<?php echo DOL_URL_ROOT ?>/mrp/ajax/ajax_bom.php?action=getBoms&idbom='+jQuery('#fk_bom').val(), function(data) {
						console.log(data);
						if (typeof data.rowid != "undefined") {
							console.log("New BOM loaded, we set values in form");
							console.log(data);
							$('#qty').val(data.qty);
							$("#mrptype").val(data.bomtype);	// We set bomtype into mrptype
							$('#mrptype').trigger('change'); // Notify any JS components that the value changed
							$("#fk_product").val(data.fk_product);
							$('#fk_product').trigger('change'); // Notify any JS components that the value changed
							$('#note_private').val(data.description);
							$('#note_private').trigger('change'); // Notify any JS components that the value changed
							$('#fk_warehouse').val(data.fk_warehouse);
							$('#fk_warehouse').trigger('change'); // Notify any JS components that the value changed
							if (typeof CKEDITOR != "undefined") {
								if (typeof CKEDITOR.instances != "undefined") {
									if (typeof CKEDITOR.instances.note_private != "undefined") {
										console.log(CKEDITOR.instances.note_private);
										CKEDITOR.instances.note_private.setData(data.description);
									}
								}
							}
						} else {
							console.log("Failed to get BOM");
						}
					});*/
                } else if (jQuery('#fk_bom').val() < 0) {
                    // Redirect to page with all fields defined except fk_bom set
                    console.log(jQuery('#fk_product').val());
                    window.location.href = '<?php echo $_SERVER["PHP_SELF"] ?>?action=create&token=<?php echo newToken(); ?>&qty=' + jQuery('#qty').val() + '&mrptype=' + jQuery('#mrptype').val() + '&fk_product=' + jQuery('#fk_product').val() + '&label=' + jQuery('#label').val() + '&fk_project=' + jQuery('#fk_project').val() + '&fk_warehouse=' + jQuery('#fk_warehouse').val();
                    /*
                    $('#qty').val('');
                    $("#fk_product").val('');
                    $('#fk_product').trigger('change'); // Notify any JS components that the value changed
                    $('#note_private').val('');
                    $('#note_private').trigger('change'); // Notify any JS components that the value changed
                    $('#fk_warehouse').val('');
                    $('#fk_warehouse').trigger('change'); // Notify any JS components that the value changed
                    */
                }
            });

            //jQuery('#fk_bom').trigger('change');
        })
    </script>
    <?php
    session_start();
    print $form->buttonsSaveCancel("Create");

    if ($objectbom->id > 0) {
        print load_fiche_titre($titlelist);

        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder centpercent">';

        $object->lines = $objectbom->lines;
//        $liness = $objectbom->accessLines;

//        $_SESSION['label'] = $objectbom->label;
        $_SESSION['bomType'] = $objectbom->bomtype;
        $_SESSION['fk_rebutwarehouse'] = $objectbom->fk_rebutwarehouse;




        $object->mrptype = $objectbom->bomtype;
        $object->bom = $objectbom;

        $object->printOriginLinesList('', array());

        print '</table>';
        print '</div>';
    }

    print '</form>';
}

// Part to edit record
if (($id || $ref) && $action == 'edit') {
    print load_fiche_titre($langs->trans("ManufacturingOrder"), '', 'mrp');

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="id" value="' . $object->id . '">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }
    if ($backtopageforcancel) {
        print '<input type="hidden" name="backtopageforcancel" value="' . $backtopageforcancel . '">';
    }

    print dol_get_fiche_head();

    $object->fields['fk_bom']['disabled'] = 1;

    print '<table class="border centpercent tableforfieldedit">' . "\n";

    // Common attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_edit.tpl.php';

    // Other attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_edit.tpl.php';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel();

    print '</form>';
}

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
    $res = $object->fetch_thirdparty();

    $head = moPrepareHead($object);

    print dol_get_fiche_head($head, 'card', $langs->trans("ManufacturingOrder"), -1, $object->picto);

    $formconfirm = '';

    // Confirmation to delete
    if ($action == 'delete') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('DeleteMo'), $langs->trans('ConfirmDeleteMo'), 'confirm_delete', '', 0, 1);
    }
    // Confirmation to delete line
    if ($action == 'deleteline') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id . '&lineid=' . $lineid, $langs->trans('DeleteLine'), $langs->trans('ConfirmDeleteLine'), 'confirm_deleteline', '', 0, 1);
    }

    // Confirmation of validation
    if ($action == 'validate') {
        // We check that object has a temporary ref
        $ref = substr($object->ref, 1, 4);
        if ($ref == 'PROV') {
            $object->fetch_product();
            $numref = $object->getNextNumRef($object->fk_product);
        } else {
            $numref = $object->ref;
        }

        $text = $langs->trans('ConfirmValidateMo', $numref);
        /*if (isModEnabled('notification'))
         {
         require_once DOL_DOCUMENT_ROOT . '/core/class/notify.class.php';
         $notify = new Notify($db);
         $text .= '<br>';
         $text .= $notify->confirmMessage('BOM_VALIDATE', $object->socid, $object);
         }*/

        $formquestion = array();
        if (isModEnabled('mrp')) {
            $langs->load("mrp");
            require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
            $formproduct = new FormProduct($db);
            $forcecombo = 0;
            if ($conf->browser->name == 'ie') {
                $forcecombo = 1; // There is a bug in IE10 that make combo inside popup crazy
            }
            $formquestion = array(
                // 'text' => $langs->trans("ConfirmClone"),
                // array('type' => 'checkbox', 'name' => 'clone_content', 'label' => $langs->trans("CloneMainAttributes"), 'value' => 1),
                // array('type' => 'checkbox', 'name' => 'update_prices', 'label' => $langs->trans("PuttingPricesUpToDate"), 'value' => 1),
            );
        }



        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('Validate'), $text, 'confirm_validate', $formquestion, 0, 1, 220);
    }

    // Clone confirmation
    if ($action == 'clone') {
        // Create an array for form
        $formquestion = array();
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('ToClone'), $langs->trans('ConfirmCloneMo', $object->ref), 'confirm_clone', $formquestion, 'yes', 1);
    }

    // Call Hook formConfirm
    $parameters = array('formConfirm' => $formconfirm, 'lineid' => $lineid);
    $reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
    if (empty($reshook)) {
        $formconfirm .= $hookmanager->resPrint;
    } elseif ($reshook > 0) {
        $formconfirm = $hookmanager->resPrint;
    }

    // Print form confirm
    print $formconfirm;


    // Object card
    // ------------------------------------------------------------
    $linkback = '<a href="' . dol_buildpath('/mrp/mo_list.php', 1) . '?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';

    $morehtmlref = '<div class="refidno">';
    /*
    // Ref bis
    $morehtmlref.=$form->editfieldkey("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->mrp->creer, 'string', '', 0, 1);
    $morehtmlref.=$form->editfieldval("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->mrp->creer, 'string', '', null, null, '', 1);*/
    // Thirdparty
    if (is_object($object->thirdparty)) {
        $morehtmlref .= $object->thirdparty->getNomUrl(1, 'customer');
        if (empty($conf->global->MAIN_DISABLE_OTHER_LINK) && $object->thirdparty->id > 0) {
            $morehtmlref .= ' (<a href="' . DOL_URL_ROOT . '/commande/list.php?socid=' . $object->thirdparty->id . '&search_societe=' . urlencode($object->thirdparty->name) . '">' . $langs->trans("OtherOrders") . '</a>)';
        }
    }
    // Project
    if (isModEnabled('project')) {
        $langs->load("projects");
        if (is_object($object->thirdparty)) {
            $morehtmlref .= '<br>';
        }
        if ($permissiontoadd) {
            $morehtmlref .= img_picto($langs->trans("Project"), 'project', 'class="pictofixedwidth"');
            if ($action != 'classify') {
                $morehtmlref .= '<a class="editfielda" href="' . $_SERVER['PHP_SELF'] . '?action=classify&token=' . newToken() . '&id=' . $object->id . '">' . img_edit($langs->transnoentitiesnoconv('SetProject')) . '</a> ';
            }
            $morehtmlref .= $form->form_project($_SERVER['PHP_SELF'] . '?id=' . $object->id, $object->socid, $object->fk_project, ($action == 'classify' ? 'projectid' : 'none'), 0, 0, 0, 1, '', 'maxwidth300');
        } else {
            if (!empty($object->fk_project)) {
                $proj = new Project($db);
                $proj->fetch($object->fk_project);
                $morehtmlref .= $proj->getNomUrl(1);
                if ($proj->title) {
                    $morehtmlref .= '<span class="opacitymedium"> - ' . dol_escape_htmltag($proj->title) . '</span>';
                }
            }
        }
    }
    $morehtmlref .= '</div>';


    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);


    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">' . "\n";

    //Mo Parent
    $mo_parent = $object->getMoParent();
    if (is_object($mo_parent)) {
        print '<tr class="field_fk_mo_parent">';
        print '<td class="titlefield fieldname_fk_mo_parent">' . $langs->trans('ParentMo') . '</td>';
        print '<td class="valuefield fieldname_fk_mo_parent">' . $mo_parent->getNomUrl(1) . '</td>';
        print '</tr>';
    }

    // Common attributes
    $keyforbreak = 'fk_warehouse';
    unset($object->fields['fk_project']);
    unset($object->fields['fk_soc']);
    include DOL_DOCUMENT_ROOT . '/core/tpl/commonfields_view.tpl.php';

    // Other attributes
    include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_view.tpl.php';

    print '</table>';
    print '</div>';
    print '</div>';

    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();


    /*
     * Lines
     */

    if (!empty($object->table_element_line)) {
        // Show object lines
        //$result = $object->getLinesArray();
        $object->fetchLines();


        print '	<form name="addproduct" id="addproduct" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . (($action != 'editline') ? '' : '#line_' . GETPOST('lineid', 'int')) . '" method="POST">
    	<input type="hidden" name="token" value="' . newToken() . '">
    	<input type="hidden" name="action" value="' . (($action != 'editline') ? 'addline' : 'updateline') . '">
    	<input type="hidden" name="mode" value="">
		<input type="hidden" name="page_y" value="">
    	<input type="hidden" name="id" value="' . $object->id . '">
    	';

        /*if (!empty($conf->use_javascript_ajax) && $object->status == 0) {
            include DOL_DOCUMENT_ROOT.'/core/tpl/ajaxrow.tpl.php';
        }*/

        if (!empty($object->lines)) {
            print '<div class="div-table-responsive-no-min">';
            print '<table id="tablelines" class="noborder noshadow" width="100%">';

            print '<tr class="liste_titre">';
            print '<td class="liste_titre">' . $langs->trans("Summary") . '</td>';
            print '<td></td>';
            print '</tr>';

            print '<tr class="oddeven">';
            print '<td>' . $langs->trans("ProductsToConsume") . '</td>';
            print '<td>';

            if (isset($_COOKIE['DELSESSIDS_6489c7a8a26573c0'])) {
                $d = $_COOKIE['DELSESSIDS_6489c7a8a26573c0'];
                $idsProdDes = explode(',', $d);
            }
            if(isset($_COOKIE['DELSESSIDS_6489c7a8a26573c0Unchecked'])){
                $uncheckedProduct = $_COOKIE['DELSESSIDS_6489c7a8a26573c0Unchecked'];
                $_SESSION['unchekedProduct'] = $uncheckedProduct;
            }

            if ($_SESSION['bomType']==0 || $_SESSION['bomType']==1 ) {
                $bomType = $_SESSION['bomType'];
                if ($bomType==1 || $bomType==0) {
                    if ($bomType==0) {
                        if (!empty($object->lines)) {
                            $i = 0;
                            foreach ($object->lines as $line) {
                                //  test if is accessoir and coched

                                if ($line->role == 'toconsume') {

                                    if (!empty($idsProdDes)) {
                                        $index = $i % count($idsProdDes);
                                        $newFkProduct = $idsProdDes[$index];

                                        foreach ($idsProdDes as $newFkProduct) {
                                            if ($newFkProduct == $line->fk_product) {
                                                if ($i) {
                                                    print ', ';
                                                }
                                                // Compare the IDs
                                                $line->fk_product = $newFkProduct;

                                                $tmpproduct = new Product($db);
                                                $tmpproduct->fetch($newFkProduct);
                                                echo $tmpproduct->getNomUrl(1);

                                                // Break the inner loop after finding a match
                                                break;
                                            }
                                        }
                                        if (!($newFkProduct == $line->fk_product)) {
                                            if (!$object->testProduct($line->fk_product)) {
                                                if ($i) {
                                                    print ', ';
                                                }
                                                $tmpproduct = new Product($db);
                                                $tmpproduct->fetch($line->fk_product);
                                                echo $tmpproduct->getNomUrl(1);
                                            }
                                        }
                                    } else {
                                        if (!$object->testProduct($line->fk_product)) {
                                            if ($i) {
                                                print ', ';
                                            }
                                            $tmpproduct = new Product($db);
                                            $tmpproduct->fetch($line->fk_product);
                                            echo $tmpproduct->getNomUrl(1);
                                        }
                                    }
                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '<tr class="oddeven">';
                        print '<td>' . $langs->trans("ProductsToProduce") . '</td>';
                        print '<td>';

                        if (!empty($object->lines)) {
                            $i = 0;
                            foreach ($object->lines as $line) {
                                if ($line->role == 'toproduce') {
                                    if ($i) {
                                        print ', ';
                                    }
                                    $tmpproduct = new Product($db);
                                    $tmpproduct->fetch($line->fk_product);
                                    print $tmpproduct->getNomUrl(1);
                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '</table>';
                        print '</div>';
                    }
                    else if ($bomType==1) {
                        if (!empty($object->lines)) {
                            $i = 0;
                            foreach ($object->lines as $line) {

                                if ($line->role == 'toconsume') {
                                    if ($i) {
                                        print ', ';
                                    }
                                    $tmpproduct = new Product($db);
                                    $tmpproduct->fetch($line->fk_product);
                                    print $tmpproduct->getNomUrl(1);
                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '<tr class="oddeven">';
                        print '<td>' . $langs->trans("ProductsToProduce") . '</td>';
                        print '<td>';

                        if (!empty($object->lines)) {
                            foreach ($object->lines as $line) {
                                if ($line->role == 'toproduce') {

                                    if (!empty($idsProdDes)) {
                                        $index = $i % count($idsProdDes);
                                        $newFkProduct = $idsProdDes[$index];


                                        foreach ($idsProdDes as $newFkProduct) {
                                            if ($newFkProduct == $line->fk_product) {

                                                // Compare the IDs
                                                $line->fk_product = $newFkProduct;

                                                $tmpproduct = new Product($db);
                                                $tmpproduct->fetch($newFkProduct);
                                                echo $tmpproduct->getNomUrl(1);

                                                if ($i) {
                                                    print ', ';
                                                }
                                                // Break the inner loop after finding a match
                                                break;
                                            }
                                        }
                                    } else {
                                        if ($i) {
                                           print ', ';
                                        }
                                        $tmpproduct = new Product($db);
                                        $tmpproduct->fetch($line->fk_product);
                                        print $tmpproduct->getNomUrl(1);
                                    }

                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '</table>';
                        print '</div>';
                    }
                    else {
                        if (!empty($object->lines)) {
                            $i = 0;
                            foreach ($object->lines as $line) {

                                if ($line->role == 'toconsume') {
                                    if ($i) {
                                        print ', ';
                                    }

                                    $tmpproduct = new Product($db);
                                    $tmpproduct->fetch($line->fk_product);

                                    print $tmpproduct->getNomUrl(1);
                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '<tr class="oddeven">';
                        print '<td>' . $langs->trans("ProductsToProduce") . '</td>';
                        print '<td>';

                        if (!empty($object->lines)) {
                            $i = 0;
//                $idsProdDes = explode(',', $d);// push this in fk_product in line
                            foreach ($object->lines as $line) {
                                if ($line->role == 'toproduce') {
                                    if ($i) {
                                        print ', ';
                                    }
                                    $tmpproduct = new Product($db);
                                    $tmpproduct->fetch($line->fk_product);
                                    print $tmpproduct->getNomUrl(1);
                                    $i++;
                                }
                            }
                        }
                        print '</td>';
                        print '</tr>';

                        print '</table>';
                        print '</div>';
                    }
                }
            } else {
                if (!empty($object->lines)) {
                    $i = 0;
                    foreach ($object->lines as $line) {

                        if ($line->role == 'toconsume') {
                            if ($i) {
                                print ', ';
                            }
                            // Find the index to get the corresponding ID from $idsProdDes


//                            $index = $i % count($idsProdDes);
//                            $newFkProduct = $idsProdDes[$index];
//
//                            // Update the fk_product in the current line
//                            $line->fk_product = $newFkProduct;

                            $tmpproduct = new Product($db);
                            $tmpproduct->fetch($line->fk_product);

                            print $tmpproduct->getNomUrl(1);
                            $i++;
                        }
                    }
                }
                print '</td>';
                print '</tr>';

                print '<tr class="oddeven">';
                print '<td>' . $langs->trans("ProductsToProduce") . '</td>';
                print '<td>';

                if (!empty($object->lines)) {
                    $i = 0;

                    foreach ($object->lines as $line) {
                        if ($line->role == 'toproduce') {
                            if ($i) {
                                print ', ';
                            }

                            $tmpproduct = new Product($db);
                            $tmpproduct->fetch($line->fk_product);
                            print $tmpproduct->getNomUrl(1);
                            $i++;
                        }
                    }
                }

                print '</td>';
                print '</tr>';

                print '</table>';
                print '</div>';
            }
        }

        print "</form>\n";
    }

    // Buttons for actions

    if ($action != 'presend' && $action != 'editline') {

        print '<div class="tabsAction">' . "\n";
        $parameters = array();
        $reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
        if ($reshook < 0) {
            setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
        }

        if (empty($reshook)) {
            // Send
            //if (empty($user->socid)) {
            //	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=presend&mode=init#formmailbeforetitle">' . $langs->trans('SendMail') . '</a>'."\n";
            //}
            // Back to draft
            if ($object->status == $object::STATUS_VALIDATED) {
                if ($permissiontoadd) {
                    // TODO Add test that production has not started
                    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=confirm_setdraft&confirm=yes&token=' . newToken() . '">' . $langs->trans("SetToDraft") . '</a>';

                    /*// for handling rebut stock
                    if(isset($_SESSION['bomType'])){
                        if($_SESSION['bomType']==1){
                    if(isset($_SESSION['unchekedProduct'])&& isset($_SESSION['fk_rebutwarehouse'])){
                        $uncheckedProduct = $_SESSION['unchekedProduct'] ;
                        $fk_rebutwarehouse = $_SESSION['fk_rebutwarehouse'];
                        if (!is_array($uncheckedProduct)) {
                            $uncheckedProduct = array($uncheckedProduct); // Convert to array
                        }

                        $uncheckedProductIds = implode(",", $uncheckedProduct); // Create a comma-separated list of IDs

                        $query = "SELECT rowid, qty FROM `llx_mrp_production` WHERE rowid IN ($uncheckedProductIds)";
                        $stock = $db->query($query);
                        $productQuantities = array();
                        $fk_product = array();
                        if ($stock) {
                            while ($row = $stock->fetch_assoc()) {
                                $fk_product[] = $row['rowid'];
                                $productQuantities[$row['rowid']] = $row['qty'];
                            }

                            // Now $productQuantities contains the quantities associated with each rowid

                            // You can loop through $uncheckedProduct to associate quantities

                            foreach ($fk_product as $productId) {
                                $newReel = $productQuantities[$productId]; // Get the quantity from productQuantities
                                $entrepotId = $fk_rebutwarehouse; // Change this to the appropriate entrepot ID
                                // Check if a record exists for the given fk_product and fk_entrepot
                                $checkQuery = "SELECT reel FROM `llx_product_stock` WHERE fk_product = $productId AND fk_entrepot = $entrepotId";
                                $checkResult = $db->query($checkQuery);
                                if ($checkResult && $checkResult->num_rows > 0) {
                                    $existingRow = $checkResult->fetch_assoc();
                                    $existingReel = $existingRow['reel'];
                                    // Calculate the new reel value
                                    $newReel += $existingReel;
                                    // Update query to add the new quantity to existing value
                                    $updateQuery = "UPDATE `llx_product_stock` SET reel = $newReel WHERE fk_product = $productId AND fk_entrepot = $entrepotId";
                                } else {
                                    // Insert query for new record
                                    $updateQuery = "INSERT INTO `llx_product_stock` (fk_product, fk_entrepot, reel) VALUES ($productId, $entrepotId, $newReel)";
                                }

                                // Execute the update/insert query
                                $updateResult = $db->query($updateQuery);
                                if (!$updateResult) {
                                    echo "Update failed for Product ID: $productId";
                                }
                            }
                        } else {
//                            echo "Query failed: " . $db->error;
                        }

                    }
                    unset($_SESSION['unchekedProduct']);
                        }
                    }*/

                }
            }

            // Modify
            if ($object->status == $object::STATUS_DRAFT) {
                if ($permissiontoadd) {
                    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=edit&token=' . newToken() . '">' . $langs->trans("Modify") . '</a>' . "\n";
                } else {
                    print '<a class="butActionRefused classfortooltip" href="#" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans('Modify') . '</a>' . "\n";
                }
            }

            // Validate
            if ($object->status == $object::STATUS_DRAFT) {
                if ($permissiontoadd) {
                    if (empty($object->table_element_line) || (is_array($object->lines) && count($object->lines) > 0)) {
                        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=validate">' . $langs->trans("Validate") . '</a>';
                    } else {
                        $langs->load("errors");
                        print '<a class="butActionRefused" href="" title="' . $langs->trans("ErrorAddAtLeastOneLineFirst") . '">' . $langs->trans("Validate") . '</a>';
                    }
                }
            }

            // Clone
            if ($permissiontoadd) {
                print dolGetButtonAction($langs->trans("ToClone"), '', 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . (!empty($object->socid) ? '&socid=' . $object->socid : "") . '&action=clone&object=mo', 'clone', $permissiontoadd);
            }

            // Cancel - Reopen
            if ($permissiontoadd) {
                if ($object->status == $object::STATUS_VALIDATED || $object->status == $object::STATUS_INPROGRESS) {
                    $arrayproduced = $object->fetchLinesLinked('produced', 0);
                    $nbProduced = 0;
                    foreach ($arrayproduced as $lineproduced) {
                        $nbProduced += $lineproduced['qty'];
                    }
                    if ($nbProduced > 0) {    // If production has started, we can close it
                        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=confirm_produced&confirm=yes&token=' . newToken() . '">' . $langs->trans("Close") . '</a>' . "\n";
                    } else {
                        print '<a class="butActionRefused" href="#" title="' . $langs->trans("GoOnTabProductionToProduceFirst", $langs->transnoentitiesnoconv("Production")) . '">' . $langs->trans("Close") . '</a>' . "\n";
                    }

                    print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=confirm_close&confirm=yes&token=' . newToken() . '">' . $langs->trans("Cancel") . '</a>' . "\n";
                }

                if ($object->status == $object::STATUS_PRODUCED || $object->status == $object::STATUS_CANCELED) {
                    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=confirm_reopen&confirm=yes&token=' . newToken() . '">' . $langs->trans("ReOpen") . '</a>' . "\n";
                }
            }

            // Delete
            print dolGetButtonAction($langs->trans("Delete"), '', 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), 'delete', $permissiontodelete);
        }
        print '</div>' . "\n";
    }


    // Select mail models is same action as presend
    if (GETPOST('modelselected')) {
        $action = 'presend';
    }

    if ($action != 'presend') {
        print '<div class="fichecenter"><div class="fichehalfleft">';
        print '<a name="builddoc"></a>'; // ancre

        // Documents
        $objref = dol_sanitizeFileName($object->ref);
        $relativepath = $objref . '/' . $objref . '.pdf';
        $filedir = $conf->mrp->dir_output . '/' . $objref;
        $urlsource = $_SERVER["PHP_SELF"] . "?id=" . $object->id;
        $genallowed = $user->rights->mrp->read; // If you can read, you can build the PDF to read content
        $delallowed = $user->hasRight("mrp", "creer"); // If you can create/edit, you can remove a file on card
        print $formfile->showdocuments('mrp:mo', $objref, $filedir, $urlsource, $genallowed, $delallowed, $object->model_pdf, 1, 0, 0, 28, 0, '', '', '', $mysoc->default_lang);

        // Show links to link elements
        $linktoelem = $form->showLinkToObjectBlock($object, null, array('mo'));
        $somethingshown = $form->showLinkedObjectBlock($object, $linktoelem, false);


        print '</div><div class="fichehalfright">';

        $MAXEVENT = 10;

        $morehtmlcenter = dolGetButtonTitle($langs->trans('SeeAll'), '', 'fa fa-bars imgforviewmode', DOL_URL_ROOT . '/mrp/mo_agenda.php?id=' . $object->id);

        // List of actions on element
        include_once DOL_DOCUMENT_ROOT . '/core/class/html.formactions.class.php';
        $formactions = new FormActions($db);
        $somethingshown = $formactions->showactions($object, $object->element, $socid, 1, '', $MAXEVENT, '', $morehtmlcenter);

        print '</div></div>';
    }

    //Select mail models is same action as presend
    if (GETPOST('modelselected')) {
        $action = 'presend';
    }

    // Presend form
    $modelmail = 'mo';
    $defaulttopic = 'InformationMessage';
    $diroutput = $conf->mrp->dir_output;
    $trackid = 'mo' . $object->id;

    include DOL_DOCUMENT_ROOT . '/core/tpl/card_presend.tpl.php';
}


llxFooter();
$db->close();
?>

<!--<script src="https://cdn.jsdelivr.net/npm/js-cookie@3.0.5/dist/js.cookie.min.js"></script>-->
<script src="./js/js.cookie.min.js"></script>
<script>
    $(window).on("load", function () {

        // Cookies.remove('DELSESSIDS_6489c7a8a26573c0Unchecked', uncheckedValues)
        var bomType = '<?php echo addslashes($objectbom->bomtype); ?>'; // get the bomType from the curent bom object
        let checkedValues = [];
        let checkedValuesMnq = [];
        var existingValues =[];// checkedValues.filter(value => !checkedValuesMnq.includes(value));
        var uncheckedValues = [];
        if (bomType==1) {

            $('.slt_mjr').prop('checked', true);
            $('.slt_mnq').prop('checked', false);

            $('.slt_mjr:checked').each(function () {
                let currentValue = $(this).val();
                checkedValues.push(currentValue);
                existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));

                Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)
            });


            $('.slt_mjr').on('change', function () {
                let currentValue = $(this).val();

                if ($(this).is(':checked')) {
                    if (checkedValues.indexOf(currentValue) === -1) {
                        checkedValues.push(currentValue); // Add to array when checked
                    }
                } else {
                    let index = checkedValues.indexOf(currentValue);
                    if (index !== -1) {
                        checkedValues.splice(index, 1); // Remove from array when unchecked
                    }
                }

             // Array of checked element values
                if (window.sessionStorage) {
                    existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));

                    Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)
                    // Cookies.set('DELSESSIDS_6489c7a8a26573c0', checkedValues)
                }
            });

        }else{
            uncheckedValues = [];
            $('.slt_mjr:not(:checked)').each(function() {

                uncheckedValues.push($(this).val());
                localStorage.setItem("DELSESSIDS_6489c7a8a26573c0Unchecked", uncheckedValues);
                Cookies.set('DELSESSIDS_6489c7a8a26573c0Unchecked', uncheckedValues)
            });

            // Now uncheckedValues array contains the values of all unchecked checkboxes
            console.log('unchecked',uncheckedValues);



        }

        // Add values of initially checked elements to the array on page load
        $('.slt_mjr:checked').each(function () {
            let currentValue = $(this).val();
            checkedValues.push(currentValue);
            existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));

            Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)

            // Cookies.set('DELSESSIDS_6489c7a8a26573c0', checkedValues)
        });

        $('.slt_mjr').on('change', function () {
            let currentValue = $(this).val();

            if ($(this).is(':checked')) {
                if (checkedValues.indexOf(currentValue) === -1) {
                    checkedValues.push(currentValue); // Add to array when checked
                }
            } else {
                let index = checkedValues.indexOf(currentValue);
                if (index !== -1) {
                    checkedValues.splice(index, 1); // Remove from array when unchecked
                }
            }
            if (window.sessionStorage) {
                existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));
                 console.log("existing:",existingValues);
                Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)

                // Cookies.set('DELSESSIDS_6489c7a8a26573c0', checkedValues)
            }

        });

        $('.slt_mnq').on('change', function () {
            var currentValue = $(this).val();

            if ($(this).is(':checked')) {
                if (checkedValuesMnq.indexOf(currentValue) === -1) {
                    checkedValuesMnq.push(currentValue); // Add to array when checked
                }
            } else {
                let index = checkedValuesMnq.indexOf(currentValue);
                if (index !== -1) {
                    checkedValuesMnq.splice(index, 1); // Remove from array when unchecked
                }
            }
            $('.slt_mjr').each(function () {
                var sltMjrValue = $(this).val();
                if (sltMjrValue == currentValue) {
                    if (checkedValuesMnq.indexOf(currentValue) === -1) {
                        $(this).show(); // Show the checkbox if the value is not in checkedValuesMnq
                    } else {
                        $(this).prop('checked',true); // on hide make sure it true to not increment the rebut stockuncheckedValues.push($(this).val());
                        // remove from the unchekedValues table
                        let val = $(this).val();
                        console.log("val : ",val)
                        uncheckedValues = uncheckedValues.filter(function(item) {
                            return item !== val;
                        })

                        $(this).hide(); // Hide the checkbox if the value is in checkedValuesMnq
                    }
                }
            });

            // create new array that containes only the value in checked value and not in checkedValuesMnq
            // Create a new array containing values in checkedValues but not in checkedValuesMnq
            // existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));
            existingValues = checkedValues.filter(value => !checkedValuesMnq.includes(value));

            Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)
            console.log("existing")
             console.log(existingValues)
            // Cookies.set('DELSESSIDS_6489c7a8a26573c0', existingValues)

        });


        // uncked value




        // Attach a change event handler to all checkboxes with the class '.slt_mjr'
        $('.slt_mjr').on('change', function() {
            uncheckedValues = []; // Reset the array on every change event
            qtevalues = [];

            // Find all unchecked checkboxes with the class '.slt_mjr'
            $('.slt_mjr:not(:checked)').each(function() {
                let qte = $("#qte_"+$(this).val()).text();
                uncheckedValues.push($(this).val());
                qtevalues.push(qte);
            });

            // Now uncheckedValues array contains the values of all unchecked checkboxes
            console.log('unchecked',uncheckedValues);
            Cookies.set('DELSESSIDS_6489c7a8a26573c0Unchecked', uncheckedValues)
            localStorage.setItem("DELSESSIDS_6489c7a8a26573c0Unchecked", uncheckedValues);
            Cookies.set('qtevalues', qtevalues)
            localStorage.setItem("qtevalues", qtevalues);

        });

    });
</script>


