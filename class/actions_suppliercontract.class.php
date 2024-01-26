<?php
/* Copyright (C) 2024 Alice Adminson <aadminson@example.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    suppliercontract/class/actions_suppliercontract.class.php
 * \ingroup suppliercontract
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

/**
 * Class ActionsSuppliercontract
 */
class ActionsSuppliercontract
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}





	/**
	 * Overloading the addMoreBoxStatsSupplier function : returns data to complete the supprlier card
	 *
	 * @param array $parameters Hook metadatas (context, etc...)
	 * @param CommonObject $object The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param string $action Current action (if set). Generally create or edit or null
	 * @param HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreBoxStatsSupplier($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		if (in_array($parameters['currentcontext'], array('thirdpartysupplier'))) {
			if (isModEnabled('contrat') && $user->hasRight('contrat', 'lire')) {
				$langs->load("contracts");
                require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
                require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
				$formfile = new FormFile($this->db);
				$MAXLIST = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');

				$this->resprints = '';

				$sql = "SELECT s.nom, s.rowid, c.rowid as id, c.ref as ref, c.statut as contract_status, c.datec as dc, c.date_contrat as dcon, c.ref_customer as refcus, c.ref_supplier as refsup, c.entity,";
				$sql .= " c.last_main_doc, c.model_pdf";
				$sql .= " FROM " . MAIN_DB_PREFIX . "societe as s, " . MAIN_DB_PREFIX . "contrat as c";
				$sql .= " WHERE c.fk_soc = s.rowid ";
				$sql .= " AND s.rowid = " . ((int)$object->id);
				$sql .= " AND c.entity IN (" . getEntity('contract') . ")";
				$sql .= " ORDER BY c.datec DESC";

				$resql = $this->db->query($sql);
				if ($resql) {
					$contrat = new Contrat($this->db);

					$num = $this->db->num_rows($resql);
					if ($num > 0) {
						$this->resprints .= '<div class="div-table-responsive-no-min">';
						$this->resprints .= '<table class="noborder centpercent lastrecordtable">';

						$this->resprints .= '<tr class="liste_titre">';
						$this->resprints .= '<td colspan="5"><table width="100%" class="nobordernopadding"><tr><td>' . $langs->trans("LastContracts", ($num <= $MAXLIST ? "" : $MAXLIST)) . '</td>';
						$this->resprints .= '<td class="right"><a class="notasortlink" href="' . DOL_URL_ROOT . '/contrat/list.php?socid=' . $object->id . '">' . $langs->trans("AllContracts") . '<span class="badge marginleftonlyshort">' . $num . '</span></a></td>';
						//print '<td width="20px" class="right"><a href="'.DOL_URL_ROOT.'/contract/stats/index.php?socid='.$object->id.'">'.img_picto($langs->trans("Statistics"),'stats').'</a></td>';
						$this->resprints .= '</tr></table></td>';
						$this->resprints .= '</tr>';
					}

					$i = 0;
					while ($i < $num && $i < $MAXLIST) {
						$objp = $this->db->fetch_object($resql);

						$contrat->id = $objp->id;
						$contrat->ref = $objp->ref ? $objp->ref : $objp->id;
						$contrat->ref_customer = $objp->refcus;
						$contrat->ref_supplier = $objp->refsup;
						$contrat->statut = $objp->contract_status;
						$contrat->last_main_doc = $objp->last_main_doc;
						$contrat->model_pdf = $objp->model_pdf;
						$contrat->fetch_lines();

						$late = '';
						foreach ($contrat->lines as $line) {
							if ($contrat->statut == Contrat::STATUS_VALIDATED && $line->statut == ContratLigne::STATUS_OPEN) {
								if (((!empty($line->date_end) ? $line->date_end : 0) + $conf->contrat->services->expires->warning_delay) < dol_now()) {
									$late = img_warning($langs->trans("Late"));
								}
							}
						}

						$this->resprints .= '<tr class="oddeven">';
						$this->resprints .= '<td class="nowraponall">';
						$this->resprints .= $contrat->getNomUrl(1, 12);
						if (!empty($contrat->model_pdf)) {
							// Preview
							$filedir = $conf->contrat->multidir_output[$objp->entity] . '/' . dol_sanitizeFileName($objp->ref);
							$file_list = null;
							if (!empty($filedir)) {
								$file_list = dol_dir_list($filedir, 'files', 0, '', '(\.meta|_preview.*.*\.png)$', 'date', SORT_DESC);
							}
							if (is_array($file_list)) {
								// Defined relative dir to DOL_DATA_ROOT
								$relativedir = '';
								if ($filedir) {
									$relativedir = preg_replace('/^' . preg_quote(DOL_DATA_ROOT, '/') . '/', '', $filedir);
									$relativedir = preg_replace('/^[\\/]/', '', $relativedir);
								}
								// Get list of files stored into database for same relative directory
								if ($relativedir) {
									completeFileArrayWithDatabaseInfo($file_list, $relativedir);

									//var_dump($sortfield.' - '.$sortorder);
									if (!empty($sortfield) && !empty($sortorder)) {    // If $sortfield is for example 'position_name', we will sort on the property 'position_name' (that is concat of position+name)
										$file_list = dol_sort_array($file_list, $sortfield, $sortorder);
									}
								}
								$relativepath = dol_sanitizeFileName($objp->ref) . '/' . dol_sanitizeFileName($objp->ref) . '.pdf';
								$this->resprints .= $formfile->showPreview($file_list, $contrat->element, $relativepath, 0);
							}
						}

						$this->resprints .= $late;
						$this->resprints .= "</td>\n";
						$this->resprints .= '<td class="nowrap">' . dol_trunc($objp->refsup, 12) . "</td>\n";
						$this->resprints .= '<td class="right" width="80px"><span title="' . $langs->trans("DateContract") . '">' . dol_print_date($this->db->jdate($objp->dcon), 'day') . "</span></td>\n";
						$this->resprints .= '<td width="20">&nbsp;</td>';
						$this->resprints .= '<td class="nowraponall right">';
						$this->resprints .= $contrat->getLibStatut(4);
						$this->resprints .= "</td>\n";
						$this->resprints .= '</tr>';
						$i++;
					}
					$this->db->free($resql);

					if ($num > 0) {
						$this->resprints .= "</table>";
						$this->resprints .= '</div>';
					}
				} else {
					setEventMessage($this->db->lasterror,'errors');
				}
			}
		}

		return 0;
	}

	/**
	 * Overloading the addMoreActionsButtons function
	 *
	 * @param array $parameters Hook metadatas (context, etc...)
	 * @param CommonObject $object The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param string $action Current action (if set). Generally create or edit or null
	 * @param HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (in_array($parameters['currentcontext'], array('thirdpartysupplier'))) {
			if (isModEnabled('contrat') && $user->hasRight('contrat', 'creer') && $object->status == 1) {
				$langs->load("contracts");
				print dolGetButtonAction('', $langs->trans('AddContract'), 'default', DOL_URL_ROOT.'/contrat/card.php?socid='.$object->id.'&amp;action=create', '');
			}
		}

		return 0;
	}
}
