<?php
/*
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
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class BankBRI extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;
    
    public function __construct()
    {
        $this->name = 'bankbri';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.8';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'Prestanesia';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 0;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(array('BANK_BRI_DETAILS', 'BANK_BRI_OWNER', 'BANK_BRI_ADDRESS', 'BANK_BRI_RESERVATION_DAYS'));
        if (!empty($config['BANK_BRI_OWNER'])) {
            $this->owner = $config['BANK_BRI_OWNER'];
        }
        if (!empty($config['BANK_BRI_DETAILS'])) {
            $this->details = $config['BANK_BRI_DETAILS'];
        }
        if (!empty($config['BANK_BRI_ADDRESS'])) {
            $this->address = $config['BANK_BRI_ADDRESS'];
        }
        if (!empty($config['BANK_BRI_RESERVATION_DAYS'])) {
            $this->reservation_days = $config['BANK_BRI_RESERVATION_DAYS'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Bank BRI', array(), 'Modules.BankBRI.Admin');
        $this->description = $this->trans('Accept payments for your products via Bank BRI transfer.', array(), 'Modules.BankBRI.Admin');
        $this->confirmUninstall = $this->trans('Are you sure about removing these details?', array(), 'Modules.BankBRI.Admin');

        if (!isset($this->owner) || !isset($this->details) || !isset($this->address)) {
            $this->warning = $this->trans('Account owner and account details must be configured before using this module.', array(), 'Modules.BankBRI.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->trans('No currency has been set for this module.', array(), 'Modules.BankBRI.Admin');
        }

        $this->extra_mail_vars = array(
            '{bankbri_owner}' => Configuration::get('BANK_BRI_OWNER'),
            '{bankbri_details}' => nl2br(Configuration::get('BANK_BRI_DETAILS')),
            '{bankbri_address}' => nl2br(Configuration::get('BANK_BRI_ADDRESS'))
        );
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentReturn') || !$this->registerHook('paymentOptions')) {
            return false;
        }

        // TODO : Cek insert new state, Custom CSS
        $newState = new OrderState();
        
        $newState->send_email = true;
        $newState->module_name = $this->name;
        $newState->invoice = false;
        $newState->color = "#002F95";
        $newState->unremovable = false;
        $newState->logable = false;
        $newState->delivery = false;
        $newState->hidden = false;
        $newState->shipped = false;
        $newState->paid = false;
        $newState->delete = false;

        $languages = Language::getLanguages(true);
        foreach ($languages as $lang) {
            if ($lang['iso_code'] == 'id') {
                $newState->name[(int)$lang['id_lang']] = 'Menunggu pembayaran via Bank BRI';
            } else {
                $newState->name[(int)$lang['id_lang']] = 'Awaiting Bank BRI Payment';
            }
            $newState->template = "bankbri";
        }

        if ($newState->add()) {
            Configuration::updateValue('PS_OS_BANKBRI', $newState->id);
            copy(dirname(__FILE__).'/logo.gif', _PS_IMG_DIR_.'tmp/order_state_mini_'.(int)$newState->id.'_1.gif');
            foreach ($languages as $lang) {
                if ($lang['iso_code'] == 'id') {
                    copy(dirname(__FILE__).'/mails/id/bankbri.html', _PS_MAIL_DIR_.'/'.strtolower($lang['iso_code']).'/bankbri.html');
                    copy(dirname(__FILE__).'/mails/id/bankbri.txt', _PS_MAIL_DIR_.'/'.strtolower($lang['iso_code']).'/bankbri.txt');
                } else {
                    copy(dirname(__FILE__).'/mails/en/bankbri.html', _PS_MAIL_DIR_.'/'.strtolower($lang['iso_code']).'/bankbri.html');
                    copy(dirname(__FILE__).'/mails/en/bankbri.txt', _PS_MAIL_DIR_.'/'.strtolower($lang['iso_code']).'/bankbri.txt');
                }
            }
        } else {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            if (!Configuration::deleteByName('BANK_BRI_CUSTOM_TEXT', $lang['id_lang'])) {
                return false;
            }
        }

        if (!Configuration::deleteByName('BANK_BRI_DETAILS')
                || !Configuration::deleteByName('BANK_BRI_OWNER')
                || !Configuration::deleteByName('BANK_BRI_ADDRESS')
                || !Configuration::deleteByName('BANK_BRI_RESERVATION_DAYS')
                || !parent::uninstall()) {
            return false;
        }
        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('BANK_BRI_DETAILS')) {
                $this->_postErrors[] = $this->trans('Account details are required.', array(), 'Modules.BankBRI.Admin');
            } elseif (!Tools::getValue('BANK_BRI_OWNER')) {
                $this->_postErrors[] = $this->trans('Account owner is required.', array(), "Modules.BankBRI.Admin");
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('BANK_BRI_DETAILS', Tools::getValue('BANK_BRI_DETAILS'));
            Configuration::updateValue('BANK_BRI_OWNER', Tools::getValue('BANK_BRI_OWNER'));
            Configuration::updateValue('BANK_BRI_ADDRESS', Tools::getValue('BANK_BRI_ADDRESS'));

            $custom_text = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                if (Tools::getIsset('BANK_BRI_CUSTOM_TEXT_'.$lang['id_lang'])) {
                    $custom_text[$lang['id_lang']] = Tools::getValue('BANK_BRI_CUSTOM_TEXT_'.$lang['id_lang']);
                }
            }
            Configuration::updateValue('BANK_BRI_RESERVATION_DAYS', Tools::getValue('BANK_BRI_RESERVATION_DAYS'));
            Configuration::updateValue('BANK_BRI_CUSTOM_TEXT', $custom_text);
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Global'));
    }

    private function _displayBankBRI()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->_displayBankBRI();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $this->context->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($this->trans('Pay by Bank BRI', array(), 'Modules.BankBRI.Shop'))
                      ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
                      ->setAdditionalInformation($this->context->smarty->fetch('module:bankbri/views/templates/hook/intro.tpl'));
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $state = $params['order']->getCurrentState();
        if (in_array(
            $state,
            array(
                Configuration::get('PS_OS_BANKBRI'),
                Configuration::get('PS_OS_OUTOFSTOCK'),
                Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
            )
        )) {
            $bankbriOwner = $this->owner;
            if (!$bankbriOwner) {
                $bankbriOwner = '___________';
            }

            $bankbriDetails = Tools::nl2br($this->details);
            if (!$bankbriDetails) {
                $bankbriDetails = '___________';
            }

            $bankbriAddress = Tools::nl2br($this->address);
            if (!$bankbriAddress) {
                $bankbriAddress = '___________';
            }

            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'total' => Tools::displayPrice(
                    $params['order']->getOrdersTotalPaid(),
                    new Currency($params['order']->id_currency),
                    false
                ),
                'bankbriDetails' => $bankbriDetails,
                'bankbriAddress' => $bankbriAddress,
                'bankbriOwner' => $bankbriOwner,
                'status' => 'ok',
                'reference' => $params['order']->reference,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ));
        } else {
            $this->smarty->assign(
                array(
                    'status' => 'failed',
                    'contact_url' => $this->context->link->getPageLink('contact', true),
                )
            );
        }

        return $this->fetch('module:bankbri/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Contact details', array(), 'Modules.BankBRI.Admin'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Account owner', array(), 'Modules.BankBRI.Admin'),
                        'name' => 'BANK_BRI_OWNER',
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Details', array(), 'Modules.BankBRI.Admin'),
                        'name' => 'BANK_BRI_DETAILS',
                        'desc' => $this->trans('Such as bank branch, IBAN number, BIC, etc.', array(), 'Modules.BankBRI.Admin'),
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Bank address', array(), 'Modules.BankBRI.Admin'),
                        'name' => 'BANK_BRI_ADDRESS',
                        'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );
        $fields_form_customization = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Customization', array(), 'Modules.WirePayment.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Reservation delay', array(), 'Modules.WirePayment.Admin'),
                        'desc' => $this->trans('Number of days the goods will be reserved', array(), 'Modules.WirePayment.Admin'),
                        'name' => 'BANK_BRI_RESERVATION_DAYS',
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Information to the customer', array(), 'Modules.WirePayment.Admin'),
                        'name' => 'BANK_BRI_CUSTOM_TEXT',
                        'desc' => $this->trans('Information about Bank BRI (processing time, starting of the shipping...)', array(), 'Modules.WirePayment.Admin'),
                        'lang' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='
            .$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form, $fields_form_customization));
    }

    public function getConfigFieldsValues()
    {
        $custom_text = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $custom_text[$lang['id_lang']] = Tools::getValue(
                'BANK_BRI_CUSTOM_TEXT_'.$lang['id_lang'],
                Configuration::get('BANK_BRI_CUSTOM_TEXT', $lang['id_lang'])
            );
        }

        return array(
            'BANK_BRI_DETAILS' => Tools::getValue('BANK_BRI_DETAILS', Configuration::get('BANK_BRI_DETAILS')),
            'BANK_BRI_OWNER' => Tools::getValue('BANK_BRI_OWNER', Configuration::get('BANK_BRI_OWNER')),
            'BANK_BRI_ADDRESS' => Tools::getValue('BANK_BRI_ADDRESS', Configuration::get('BANK_BRI_ADDRESS')),
            'BANK_BRI_RESERVATION_DAYS' => Tools::getValue('BANK_BRI_RESERVATION_DAYS', Configuration::get('BANK_BRI_RESERVATION_DAYS')),
            'BANK_BRI_CUSTOM_TEXT' => $custom_text,
        );
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->trans('%1$s (tax incl.)', array(), 'Modules.BankBRI.Shop'),
            Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH))
        );

         $bankbriOwner = $this->owner;
        if (!$bankbriOwner) {
            $bankbriOwner = '___________';
        }

        $bankbriDetails = Tools::nl2br($this->details);
        if (!$bankbriDetails) {
            $bankbriDetails = '___________';
        }

        $bankbriAddress = Tools::nl2br($this->address);
        if (!$bankbriAddress) {
            $bankbriAddress = '___________';
        }

        $bankbriReservationDays = Configuration::get('BANK_BRI_RESERVATION_DAYS');
        if (false === $bankbriReservationDays) {
            $bankbriReservationDays = 7;
        }

        $bankbriCustomText = Tools::nl2br(Configuration::get('BANK_BRI_CUSTOM_TEXT', $this->context->language->id));
        if (false === $bankbriCustomText) {
            $bankbriCustomText = '';
        }

        return array(
            'total' => $total,
            'bankbriDetails' => $bankbriDetails,
            'bankbriAddress' => $bankbriAddress,
            'bankbriOwner' => $bankbriOwner,
            'bankbriReservationDays' => (int)$bankbriReservationDays,
            'bankbriCustomText' => $bankbriCustomText,
        );
    }
}
