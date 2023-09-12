<?php
/* Copyright (C) 2019	Laurent Destailleur (eldy)	<eldy@users.sourceforge.net>
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
 *	\file       htdocs/mrp/ajax/ajax_bom.php
 *	\brief      Ajax search component for Mrp. It get BOM content.
 */

//if (! defined('NOREQUIREUSER'))	define('NOREQUIREUSER','1');	// Not disabled cause need to load personalized language
//if (! defined('NOREQUIREDB'))		define('NOREQUIREDB','1');		// Not disabled cause need to load personalized language
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', '1');
}
//if (! defined('NOREQUIRETRAN'))		define('NOREQUIRETRAN','1');
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
require '../../main.inc.php'; // Load $user and permissions

$idbom = GETPOST('idbom', 'alpha');
//$action = GETPOST('action', 'aZ09');


/*
 * View
 */

top_httphead('application/json');

if (isset($_POST['id'])) {
    ob_start();

    global $db;
    $id = $_POST['id'];
    $qte = $_POST['qte'];

    $updateQte = "UPDATE `llx_bom_bomline` SET qty = $qte WHERE rowid = $id";
    // Execute the update query
    $updateQte = $db->query($updateQte);


    echo "Quantity updated successfully";


}
