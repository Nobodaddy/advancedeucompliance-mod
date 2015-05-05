<?php
/**
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2015 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

// Include required entities
include_once dirname(__FILE__).'/entities/AeucCMSRoleEmailEntity.php';
include_once dirname(__FILE__).'/entities/AeucEmailEntity.php';

class Advancedeucompliance extends Module
{
	/* Class members */
	protected $config_form = false;
	private $repository_manager;
	private $filesystem;
	private $emails;
	protected $_errors;

	/* Constants used for LEGAL/CMS Management */
	// TODO: Remove this once in DB
	const LEGAL_NO_ASSOC		= 'NO_ASSOC';
	const LEGAL_NOTICE			= 'LEGAL_NOTICE';
	const LEGAL_CONDITIONS 		= 'LEGAL_CONDITIONS';
	const LEGAL_REVOCATION 		= 'LEGAL_REVOCATION';
	const LEGAL_REVOCATION_FORM = 'LEGAL_REVOCATION_FORM';
	const LEGAL_PRIVACY 		= 'LEGAL_PRIVACY';
	const LEGAL_ENVIRONMENTAL 	= 'LEGAL_ENVIRONMENTAL';
	const LEGAL_SHIP_PAY 		= 'LEGAL_SHIP_PAY';

	public function __construct(RepositoryManager $repository_manager, FileSystem $fs, Email $email)
	{
		$this->name = 'advancedeucompliance';
		$this->tab = 'administration';
		$this->version = '1.0.0';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;
		$this->bootstrap = true;

		parent::__construct();

		/* Register dependencies to module */
		$this->repository_manager = $repository_manager;
		$this->filesystem = $fs;
		$this->emails = $email;

		$this->displayName = $this->l('Advanced EU Compliance');
		$this->description = $this->l('This module will help European merchants to get compliant with their countries e-commerce laws');
		$this->confirmUninstall = $this->l('Are you sure you cant to uninstall this module ?');

		/* Init errors var */
		$this->_errors = array();
	}

	/**
	 * Don't forget to create update methods if needed:
	 * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
	 */
	public function install()
	{
		return parent::install() &&
				$this->loadTables() &&
				$this->registerHook('displayProductPriceBlock') &&
				$this->registerHook('overrideTOSDisplay') &&
				$this->registerHook('actionEmailAddAfterContent') &&
				$this->createConfig();
	}

	public function uninstall()
	{
		return parent::uninstall() &&
				$this->dropConfig() &&
				$this->unloadTables();
	}

	public function createConfig()
	{
		// @TODO: Create config from localization pack ? (ATM everythings goeas to TRUE)
		return Configuration::updateValue('AEUC_FEAT_TELL_A_FRIEND', true) &&
				Configuration::updateValue('AEUC_FEAT_REORDER', true) &&
				Configuration::updateValue('AEUC_LABEL_DELIVERY_TIME', true) &&
				Configuration::updateValue('AEUC_LABEL_SPECIFIC_PRICE', true) &&
				Configuration::updateValue('AEUC_LABEL_TAX_INC_EXC', true) &&
				Configuration::updateValue('AEUC_LABEL_WEIGHT', true) &&
				Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', true) &&
				Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', true) &&
				Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', true) &&
				Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', true);
	}

	public function unloadTables()
	{
		$state = true;
		include_once dirname(__FILE__).'/install/sql_install.php';
		foreach ($sql as $name => $v)
			$state &= Db::getInstance()->execute('DROP TABLE IF EXISTS '.$name);

		return $state;
	}

	public function loadTables()
	{
		$state = true;

		// Create module's table
		include_once dirname(__FILE__).'/install/sql_install.php';
		foreach ($sql as $s)
			$state &= Db::getInstance()->execute($s);

		// Fillin CMS ROLE - @Todo: Parser from loc pack to get base configuration
		$roles_array = $this->getCMSRoles();
		$roles = array_keys($roles_array);
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');

		foreach ($roles as $role)
		{
			if (!$cms_role_repository->getRoleByName($role))
			{
				$cms_role = $cms_role_repository->createNewRecord();
				$cms_role->id_cms = 0; // No assoc at this time
				$cms_role->name = $role;
				$state &= (bool)$cms_role->save();
			}
		}

		// Fill-in aeuc_mail table
		foreach ($this->emails->getAvailableMails() as $mail)
		{
			$new_email = new AeucEmailEntity();
			$new_email->filename = (string)$mail;
			$new_email->display_name = (string)ucfirst(str_replace(array('_', '-'), ' ', $mail));
			$new_email->save();

			unset($new_email);
		}

		return $state;
	}


	public function dropConfig()
	{
		return Configuration::deleteByName('AEUC_FEAT_TELL_A_FRIEND') &&
				Configuration::deleteByName('AEUC_FEAT_REORDER') &&
				Configuration::deleteByName('AEUC_LABEL_DELIVERY_TIME') &&
				Configuration::deleteByName('AEUC_LABEL_SPECIFIC_PRICE') &&
				Configuration::deleteByName('AEUC_LABEL_TAX_INC_EXC') &&
				Configuration::deleteByName('AEUC_LABEL_WEIGHT') &&
				Configuration::deleteByName('AEUC_FEAT_ADV_PAYMENT_API') &&
				Configuration::deleteByName('AEUC_LABEL_REVOCATION_TOS') &&
				Configuration::deleteByName('AEUC_LABEL_SHIPPING_INC_EXC');
	}

	public function hookActionEmailAddAfterContent($param)
	{
		if (!isset($param['template']) || !isset($param['template_html']) || !isset($param['template_txt']))
			return;

		$tpl_name = (string)$param['template'];
		$tpl_name_exploded = explode('.', $tpl_name);
		if (is_array($tpl_name_exploded))
			$tpl_name = (string)$tpl_name_exploded[0];

		$id_lang = (int)$param['id_lang'];
		$mail_id = AeucEmailEntity::getMailIdFromTplFilename($tpl_name);


		if (!isset($mail_id['id_mail']))
			return;

		$mail_id = (int)$mail_id['id_mail'];
		$cms_role_ids = AeucCMSRoleEmailEntity::getCMSRoleIdsFromIdMail($mail_id);

		if (!$cms_role_ids)
			return;

		$tmp_cms_role_list = array();
		foreach ($cms_role_ids as $cms_role_id)
			$tmp_cms_role_list[] = $cms_role_id['id_cms_role'];

		$sql_where_in_cmsroles = implode(', ', $tmp_cms_role_list);
		unset($tmp_cms_role_list);
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_ids = $cms_role_repository->getCMSIdsWhereCMSRoleIdIn($sql_where_in_cmsroles);

		if (!$cms_ids)
			return;

		$cms_repo = $this->repository_manager->getRepository('CMS');
		foreach ($cms_ids as $cms_id) {
			$cms_content = $cms_repo->getCMSContent((int)$cms_id['id_cms'], $id_lang);

			if (!isset($cms_content['content']))
				continue;

			$cms_content = $cms_content['content'];
			$param['template_html'] .= $cms_content;
			$param['template_txt'] .= strip_tags($cms_content, true);
		}
	}

	public function hookOverrideTOSDisplay($param)
	{
		$has_tos_override_opt = (bool)Configuration::get('AEUC_LABEL_REVOCATION_TOS');
		$cms_repository = $this->repository_manager->getRepository('CMS');
		// Check first if LEGAL_REVOCATION CMS Role is been set before doing anything here
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->getCMSIdAssociatedFromName(Advancedeucompliance::LEGAL_REVOCATION);

		if (!$has_tos_override_opt || !isset($cms_page_associated['id_cms']) || (int)$cms_page_associated['id_cms'] == 0)
			return false;

		// Get IDs of CMS pages required
		$cms_conditions_id = (int)Configuration::get('PS_CONDITIONS_CMS_ID');
		$cms_revocation_id = (int)$cms_page_associated['id_cms'];

		// Get misc vars
		$id_lang = (int)$this->context->language->id;
		$is_ssl_enabled = (bool)Configuration::get('PS_SSL_ENABLED');
		$checkedTos = $this->context->cart->checkedTos ? true : false;

		// Get CMS OBJs
		$cms_conditions = $cms_repository->getCMSById($cms_conditions_id, $id_lang);
		$cms_revocations = $cms_repository->getCMSById($cms_revocation_id, $id_lang);

		// Get links to these pages
		$link_conditions = $this->context->link->getCMSLink($cms_conditions, $cms_conditions->link_rewrite, $is_ssl_enabled);
		$link_revocations = $this->context->link->getCMSLink($cms_revocations, $cms_revocations->link_rewrite, $is_ssl_enabled);

		if (!strpos($link_conditions, '?'))
			$link_conditions .= '?content_only=1';
		else
			$link_conditions .= '&content_only=1';

		if (!strpos($link_revocations, '?'))
			$link_revocations .= '?content_only=1';
		else
			$link_revocations .= '&content_only=1';

		$this->context->smarty->assign(array(
			'checkedTOS' => $checkedTos,
			'link_conditions' => $link_conditions,
			'link_revocations' => $link_revocations
		));

		$content = $this->context->smarty->fetch($this->local_path.'views/templates/hook/hookOverrideTOSDisplay.tpl');
		return $content;
	}

	public function hookDisplayProductPriceBlock($param)
	{
		if (!isset($param['product']) || !isset($param['type']))
			return;

		$product = $param['product'];

		if (is_array($product))
			$product = new Product((int)$product['id_product']);
		if (!Validate::isLoadedObject($product))
			return;

		$content_to_return = '';


		/* Handle taxes  Inc./Exc.*/
		if ($param['type'] == 'price' && (bool)Configuration::get('AEUC_LABEL_TAX_INC_EXC') === true)
		{
			// @Todo: REfactor with templates
			if ((bool)Configuration::get('PS_TAX') === true)
				$content_to_return.= '<br/>'.$this->l('Tax included');
			else
				$content_to_return .= '<br/>'.$this->l('Tax excluded');
		}

		/* Handle Shipping Inc./Exc. */
		if ($param['type'] == 'price' && (bool)Configuration::get('AEUC_LABEL_SHIPPING_INC_EXC') === true)
		{
			// @Todo: REfactor with templates
			if ($product->is_virtual)
			{
				$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
				$cms_repository = $this->repository_manager->getRepository('CMS');
				$cms_page_associated = $cms_role_repository->getCMSIdAssociatedFromName(Advancedeucompliance::LEGAL_SHIP_PAY);

				if (isset($cms_page_associated['id_cms']) && (int)$cms_page_associated['id_cms'] != 0)
				{
					$cms_ship_pay_id = (int)$cms_page_associated['id_cms'];
					$cms_revocations = $cms_repository->getCMSById($cms_ship_pay_id, $this->context->language->id);
					$is_ssl_enabled = (bool)Configuration::get('PS_SSL_ENABLED');
					$link_ship_pay = $this->context->link->getCMSLink($cms_revocations, $cms_revocations->link_rewrite, $is_ssl_enabled);

					if (!strpos($link_ship_pay, '?'))
						$link_ship_pay .= '?content_only=1';
					else
						$link_ship_pay .= '&content_only=1';

					$content_to_return .= 	'<br/>' .
											'<a href="'.$link_ship_pay.'" target="_blank">'.
												$this->l('Shipping included').
											'</a>';
				}
			}
		}

		/* Handles product's weight */
		if ($param['type'] == 'weight' && (bool)Configuration::get('PS_DISPLAY_PRODUCT_WEIGHT') === true &&
		isset($param['hook_origin']) && $param['hook_origin'] == 'product_sheet')
		{
			if ((int)$product->weight)
			{
				$rounded_weight = round((float)$product->weight,
										Configuration::get('PS_PRODUCT_WEIGHT_PRECISION'));
				$content_to_return .= sprintf($this->l('Weight: %s'), $rounded_weight.' '.Configuration::get('PS_WEIGHT_UNIT'));
			}
		}

		return $content_to_return;
	}

	/**
	 * Load the configuration form
	 */
	public function getContent()
	{
		/**
		 * If values have been submitted in the form, process.
		 */
		$success_band = $this->_postProcess();

		$this->context->smarty->assign('module_dir', $this->_path);
		$this->context->smarty->assign('errors', $this->_errors);

		// Render all required form for each 'part'
		$formLabelsManager = $this->renderFormLabelsManager();
		$formFeaturesManager = $this->renderFormFeaturesManager();
		$formLegalContentManager = $this->renderFormLegalContentManager();
		$formEmailAttachmentsManager = $this->renderFormEmailAttachmentsManager();

		return 	$success_band.
				$formLabelsManager.
				$formFeaturesManager.
				$formLegalContentManager.
				$formEmailAttachmentsManager;
	}

	/**
	 * Save form data.
	 */
	protected function _postProcess()
	{
        $has_processed_something = false;

        $post_keys_switchable = array_keys(
            array_merge(
                $this->getConfigFormLabelsManagerValues(),
                $this->getConfigFormFeaturesManagerValues()
            )
        );

		$post_keys_complex = array(
			'AEUC_legalContentManager',
			'AEUC_emailAttachmentsManager'
		);

		$received_values = Tools::getAllValues();

        foreach (array_keys($received_values) as $key_received)
        {
			/* Case its one of form with only switches in it */
			if (in_array($key_received, $post_keys_switchable)) {
				$is_option_active = Tools::getValue($key_received);
				$key = Tools::strtolower($key_received);
				$key = Tools::toCamelCase($key);
				if (method_exists($this, 'process' . $key))
				{

					$this->{'process' . $key}($is_option_active);
					$has_processed_something = true;
				}
				continue;
			}
			/* Case we are on more complex forms */
			if (in_array($key_received, $post_keys_complex))
			{
				// Clean key
				$key = Tools::strtolower($key_received);
				$key = Tools::toCamelCase($key, true);

				if (method_exists($this, 'process' . $key))
				{
					$this->{'process' . $key}();
					$has_processed_something = true;
				}
			}

        }

		if ($has_processed_something)
			return (count($this->_errors) ? $this->displayError($this->_errors) : '').
					$this->displayConfirmation($this->l('Settings saved successfully!'));
		else
			return (count($this->_errors) ? $this->displayError($this->_errors) : '').'';
	}

	protected function processAeucEmailAttachmentsManager()
	{
		$json_attach_assoc = json_decode(Tools::getValue('emails_attach_assoc'));

		if (!$json_attach_assoc)
			return;

		// Empty previous assoc to make new ones
		AeucCMSRoleEmailEntity::truncate();

		foreach ($json_attach_assoc as $assoc)
		{
			$assoc_obj = new AeucCMSRoleEmailEntity();
			$assoc_obj->id_mail = $assoc->id_mail;
			$assoc_obj->id_cms_role = $assoc->id_cms_role;

			if (!$assoc_obj->save())
				$this->_errors[] = $this->l("An email attachment to a CMS role has failed.");
		}
	}

	protected function processAeucLabelRevocationTOS($is_option_active)
	{
		// Check first if LEGAL_REVOCATION CMS Role is been set before doing anything here
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->getCMSIdAssociatedFromName(Advancedeucompliance::LEGAL_REVOCATION);

		// @TODO: Fill error member attribute
		if (!isset($cms_page_associated['id_cms']) || (int)$cms_page_associated['id_cms'] == 0)
		{
			$this->_errors[] = $this->l('CMS Role "Legal Revocation" has not been associated yet. Therefore we cannot activate "Revocation Terms" option');
			return;
		}

		if ((bool)$is_option_active)
			Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', true);
		else
			Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', false);
	}

	protected function processAeucLabelShippingIncExc($is_option_active)
	{
		// Check first if LEGAL_REVOCATION CMS Role is been set before doing anything here
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->getCMSIdAssociatedFromName(Advancedeucompliance::LEGAL_SHIP_PAY);

		// @TODO: Fill error member attribute
		if (!isset($cms_page_associated['id_cms']) || (int)$cms_page_associated['id_cms'] == 0)
		{
			$this->_errors[] = $this->l('CMS Role "Legal Shipping" has not been associated yet. Therefore we cannot activate "Shipping Exc/Inc. Label Terms" option');
			return;
		}

		if ((bool)$is_option_active)
			Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', true);
		else
			Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', false);

	}

	protected function processAeucLabelTaxIncExc($is_option_active)
	{
		$id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$countries = Country::getCountries($id_lang, true, false, false);
		foreach ($countries as $id_country => $country_details)
		{

			$country = new Country((int)$country_details['id_country']);
			if (Validate::isLoadedObject($country))
			{
				$country->display_tax_label = ($is_option_active ? 0 : 1);
				if (!$country->update())
					$this->_errors[] = $this->l('A country could not be updated for Tax INC/EXC label');
			}
		}
	}

	protected function processAeucFeatAdvPaymentApi($is_option_active)
	{
		if ((bool)$is_option_active)
		{
			Configuration::updateValue('PS_ADVANCED_PAYMENT_API', true);
			Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', true);
		}
		else
		{
			Configuration::updateValue('PS_ADVANCED_PAYMENT_API', false);
			Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', false);
		}
	}

    protected function processAeucFeatTellAFriend($is_option_active)
    {
        $staf_module = Module::getInstanceByName('sendtoafriend');

        if ((bool)$staf_module->active && (bool)$is_option_active)
            $staf_module->disable();
        else if (!(bool)$staf_module->active && !(bool)$is_option_active)
            $staf_module->enable();
    }

    protected function processAeucFeatReorder($is_option_active)
	{
        $is_ps_reordering_active = Configuration::get('PS_REORDERING');

        if ((bool)$is_ps_reordering_active && (bool)$is_option_active)
			Configuration::updateValue('PS_REORDERING', false);
        else if (!(bool)$is_ps_reordering_active && !(bool)$is_option_active)
			Configuration::updateValue('PS_REORDERING', true);
    }

	protected function processAeucLabelWeight($is_option_active)
	{
		$is_ps_display_weight_active = Configuration::get('PS_DISPLAY_PRODUCT_WEIGHT');

		if (!(bool)$is_ps_display_weight_active && (bool)$is_option_active)
			Configuration::updateValue('PS_DISPLAY_PRODUCT_WEIGHT', true);
		else if ((bool)$is_ps_display_weight_active && !(bool)$is_option_active)
			Configuration::updateValue('PS_DISPLAY_PRODUCT_WEIGHT', false);
	}

	protected function processAeucLegalContentManager()
	{

		$posted_values = Tools::getAllValues();
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');

		foreach ($posted_values as $key_name => $assoc_cms_id)
		{
			if (strpos($key_name, 'CMSROLE_') !== false)
			{
				$exploded_key_name = explode('_', $key_name);
				$cms_role = $cms_role_repository->getRecordById((int)$exploded_key_name[1]);
				$cms_role->id_cms = (int)$assoc_cms_id;
				$cms_role->update();
			}
		}
		unset($cms_role);
	}


	// @TODO: To be moved to the core ?
	protected function getCMSRoles()
	{
		return array(
			Advancedeucompliance::LEGAL_NOTICE 			=> $this->l('Legal notice'),
			Advancedeucompliance::LEGAL_CONDITIONS 		=> $this->l('Conditions'),
			Advancedeucompliance::LEGAL_REVOCATION 		=> $this->l('Revocation'),
			Advancedeucompliance::LEGAL_REVOCATION_FORM => $this->l('Revocation Form'),
			Advancedeucompliance::LEGAL_PRIVACY 		=> $this->l('Privacy'),
			Advancedeucompliance::LEGAL_ENVIRONMENTAL 	=> $this->l('Environmental'),
			Advancedeucompliance::LEGAL_SHIP_PAY		=> $this->l('Shipping and payment')
		);
	}


	/**
	 * Create the form that will let user choose all the wording options
	 */
	protected function renderFormLabelsManager()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitAEUC_labelsManager';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormLabelsManagerValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigFormLabelsManager()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigFormLabelsManager()
	{
		return array(
			'form' => array(
				'legend' => array(
				'title' => $this->l('Labeling Management'),
				'icon' => 'icon-tags',
				),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Display delivery time label'),
						'name' => 'AEUC_LABEL_DELIVERY_TIME',
						'is_bool' => true,
						'desc' => $this->l('Whether to display estimated delivery time on products'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Display specific price label'),
						'name' => 'AEUC_LABEL_SPECIFIC_PRICE',
						'is_bool' => true,
						'desc' => $this->l('Whether to display a label before products with specific price'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Display Tax "Inc./Excl." label'),
						'name' => 'AEUC_LABEL_TAX_INC_EXC',
						'is_bool' => true,
						'desc' => $this->l('Whether to display tax included/excluded label next to product\'s price'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Display Shipping "Inc./Excl." label'),
						'name' => 'AEUC_LABEL_SHIPPING_INC_EXC',
						'is_bool' => true,
						'desc' => $this->l('Whether to display shipping included/excluded label next to product\'s price'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Display product weight label'),
						'name' => 'AEUC_LABEL_WEIGHT',
						'is_bool' => true,
						'desc' => $this->l('Whether to display product\'s weight on product\'s sheet (when available)'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Display Revocation Terms within TOS'),
						'name' => 'AEUC_LABEL_REVOCATION_TOS',
						'is_bool' => true,
						'desc' => $this->l('Whether to display Revocation Terms CMS page within TOS text (NB: Related CMS Role need to be set)'),
						'disable' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormLabelsManagerValues()
	{
		return array(
			'AEUC_LABEL_DELIVERY_TIME' => Configuration::get('AEUC_LABEL_DELIVERY_TIME'),
			'AEUC_LABEL_SPECIFIC_PRICE' => Configuration::get('AEUC_LABEL_SPECIFIC_PRICE'),
			'AEUC_LABEL_TAX_INC_EXC' => Configuration::get('AEUC_LABEL_TAX_INC_EXC'),
			'AEUC_LABEL_WEIGHT' => Configuration::get('AEUC_LABEL_WEIGHT'),
			'AEUC_LABEL_REVOCATION_TOS' => Configuration::get('AEUC_LABEL_REVOCATION_TOS'),
			'AEUC_LABEL_SHIPPING_INC_EXC' => Configuration::get('AEUC_LABEL_SHIPPING_INC_EXC')
		);
	}

	/**
	 * Create the form that will let user choose all the wording options
	 */
	protected function renderFormFeaturesManager()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitAEUC_featuresManager';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormFeaturesManagerValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigFormFeaturesManager()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigFormFeaturesManager()
	{
		return array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Features Management'),
					'icon' => 'icon-cogs',
				),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Disable "Tell A Friend" feature'),
						'name' => 'AEUC_FEAT_TELL_A_FRIEND',
						'is_bool' => true,
						'desc' => $this->l('Whether to disable "Tell A Friend" feature'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Disable "Reorder" feature'),
						'name' => 'AEUC_FEAT_REORDER',
						'is_bool' => true,
						'desc' => $this->l('Whether to disable "Reorder" feature'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Enable "Advanced Payment API" feature'),
						'name' => 'AEUC_FEAT_ADV_PAYMENT_API',
						'is_bool' => true,
						'desc' => $this->l('Whether to enable "Advanced Payment API" feature'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormFeaturesManagerValues()
	{
		return array(
			'AEUC_FEAT_TELL_A_FRIEND' => Configuration::get('AEUC_FEAT_TELL_A_FRIEND'),
			'AEUC_FEAT_REORDER' => Configuration::get('AEUC_FEAT_REORDER'),
			'AEUC_FEAT_ADV_PAYMENT_API' => Configuration::get('AEUC_FEAT_ADV_PAYMENT_API')
		);
	}

	/**
	 * Create the form that will let user manage his legal page trough "CMS" feature
	 */
	protected function renderFormLegalContentManager()
	{
		$cms_roles_aeuc = $this->getCMSRoles();
		$cms_repository = $this->repository_manager->getRepository('CMS');
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_roles = $cms_role_repository->getCMSRolesWhereNamesIn(array_keys($cms_roles_aeuc));
		$cms_roles_assoc = array();
		$id_lang = Context::getContext()->employee->id_lang;

		foreach ($cms_roles as $cms_role)
		{
			if ((int)$cms_role['id_cms'] != 0)
			{
				$cms_entity = $cms_repository->getRecordById((int)$cms_role['id_cms'], true);
				$assoc_cms_name = $cms_entity->meta_title[(int)$id_lang];
			}
			else
				$assoc_cms_name = $this->l('No association (means disabled)');

			$cms_roles_assoc[(int)$cms_role['id_cms_role']] = array('id_cms' => (int)$cms_role['id_cms'],
																	'page_title' => (string)$assoc_cms_name,
																	'role_title' => (string)$cms_roles_aeuc[$cms_role['name']]);
		}

		$cms_pages = $cms_repository->getCMSPagesList();
		array_unshift($cms_pages, array('id_cms' => 0, 'meta_title' => $this->l('No association (means disabled)')));

		$this->context->smarty->assign(array(
			'cms_roles_assoc' => $cms_roles_assoc,
			'cms_pages' => $cms_pages,
			'form_action' => '#',
			'add_cms_link' => $this->context->link->getAdminLink('AdminCMS')
		));
		$content = $this->context->smarty->fetch($this->local_path.'views/templates/admin/legal_cms_manager_form.tpl');
		return $content;
	}

	protected function renderFormEmailAttachmentsManager()
	{
		$cms_roles_aeuc = $this->getCMSRoles();
		$cms_role_repository = $this->repository_manager->getRepository('CMSRole');
		$cms_roles_associated = $cms_role_repository->getCMSRolesAssociated();
		$cms_roles_full = $cms_role_repository->getCMSRolesWhereNamesIn(array_keys($cms_roles_aeuc));
		$incomplete_cms_role_association_warning = false;
		$legal_options = array();
		$cleaned_mails_names = array();

		if (count($cms_roles_associated) != count($cms_roles_full)) {
			$incomplete_cms_role_association_warning = $this->displayWarning(
				$this->l('You do not have associated all roles,
				therefore you cannot associate all of them to mails (check above section)')
			);
		}

		foreach ($cms_roles_associated as $role) {
			$list_id_mail_assoc = AeucCMSRoleEmailEntity::getIdEmailFromCMSRoleId((int)$role['id_cms_role']);
			$clean_list = array();

			foreach ($list_id_mail_assoc as $list_id_mail_assoc) {
				$clean_list[] = $list_id_mail_assoc['id_mail'];
			}

			$legal_options[$role['name']] = array(
				'name' => $cms_roles_aeuc[$role['name']],
				'id' => $role['id_cms_role'],
				'list_id_mail_assoc' => $clean_list
			);
		}

		foreach (AeucEmailEntity::getAll() as $email) {
			$cleaned_mails_names[] = $email;
		}

		$this->context->smarty->assign(array(
			'has_assoc' => $cms_roles_associated,
			'incomplete_cms_role_association_warning' => $incomplete_cms_role_association_warning,
			'mails_available' => $cleaned_mails_names,
			'legal_options' => $legal_options
		));

		$content = $this->context->smarty->fetch($this->local_path.'views/templates/admin/email_attachments_form.tpl');
		// Insert JS in the page
		$this->context->controller->addJS(($this->_path).'assets/js/email_attachement.js');

		return $content;
	}

}
