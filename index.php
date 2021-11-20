<?php
/*
Plugin Name: درگاه سداد بانک ملی
Plugin URI: https://sadadpsp.ir/
Description: درگاه پرداخت سداد بانک ملی برای افزونه Paid Memberships Pro
Version: 1.1
Author: آلماتک
Author URI: http://almaatech.ir
*/

//load classes init method
add_action('plugins_loaded', 'load_Melli_pmpro_class', 11);
add_action('plugins_loaded', ['PMProGateway_Melli', 'init'], 12);

function load_Melli_pmpro_class()
{
    if (class_exists('PMProGateway')) {
        class PMProGateway_Melli extends PMProGateway
        {
            public function PMProGateway_Melli($gateway = null)
            {
                $this->gateway = $gateway;
                $this->gateway_environment = pmpro_getOption('gateway_environment');

                return $this->gateway;
            }

            public static function init()
            {
                //make sure Stripe is a gateway option
                add_filter('pmpro_gateways', ['PMProGateway_Melli', 'pmpro_gateways']);

                //add fields to payment settings
                add_filter('pmpro_payment_options', ['PMProGateway_Melli', 'pmpro_payment_options']);
                add_filter('pmpro_payment_option_fields', ['PMProGateway_Melli', 'pmpro_payment_option_fields'], 10, 2);
                $gateway = pmpro_getOption('gateway');

                if ($gateway == 'Melli') {
                    add_filter('pmpro_checkout_before_change_membership_level', ['PMProGateway_Melli', 'pmpro_checkout_before_change_membership_level'], 10, 2);
                    add_filter('pmpro_include_billing_address_fields', '__return_false');
                    add_filter('pmpro_include_payment_information_fields', '__return_false');
                    add_filter('pmpro_required_billing_fields', ['PMProGateway_Melli', 'pmpro_required_billing_fields']);
                }

                add_action('wp_ajax_nopriv_Melli-ins', ['PMProGateway_Melli', 'pmpro_wp_ajax_Melli_ins']);
                add_action('wp_ajax_Melli-ins', ['PMProGateway_Melli', 'pmpro_wp_ajax_Melli_ins']);
            }

            public static function pmpro_gateways($gateways)
            {
                if (empty($gateways['Melli'])) {
                    $gateways['Melli'] = 'بانک ملی';
                }

                return $gateways;
            }

            public static function getGatewayOptions()
            {
                $options = [
                    'melli_merchant_id',
                    'melli_terminal_id',
                    'melli_terminal_key',
                    'currency',
                    'tax_rate',
                ];

                return $options;
            }

            public static function pmpro_payment_options($options)
            {
                $Melli_options = self::getGatewayOptions();
                $options = array_merge($Melli_options, $options);
                return $options;
            }

            public static function pmpro_required_billing_fields($fields)
            {
                unset($fields['bfirstname']);
                unset($fields['blastname']);
                unset($fields['baddress1']);
                unset($fields['bcity']);
                unset($fields['bstate']);
                unset($fields['bzipcode']);
                unset($fields['bphone']);
                unset($fields['bemail']);
                unset($fields['bcountry']);
                unset($fields['CardType']);
                unset($fields['AccountNumber']);
                unset($fields['ExpirationMonth']);
                unset($fields['ExpirationYear']);
                unset($fields['CVV']);

                return $fields;
            }

            public static function pmpro_payment_option_fields($values, $gateway)
            {
                $merchant_id = esc_attr($values['melli_merchant_id']);
                $terminal_id = esc_attr($values['melli_terminal_id']);
                $terminal_key = esc_attr($values['melli_terminal_key']);

                $style = ($gateway != 'Melli') ? 'style="display: none;"' : '';
                $form_fields = <<< FORM
                    <tr class="pmpro_settings_divider gateway gateway_Melli" {$style}>
                        <td colspan="2">تنظیمات درگاه سداد بانک ملی</td>
                    </tr>
                    <tr class="gateway gateway_Melli" {$style}>
                        <th scope="row" valign="top">
                            <label for="melli_merchant_id">شماره پذیرنده:</label>
                        </th>
                        <td>
                            <input type="text" id="melli_merchant_id" name="melli_merchant_id" size="60" value="{$merchant_id}" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_Melli" {$style}>
                        <th scope="row" valign="top">
                            <label for="melli_terminal_id">شماره ترمینال:</label>
                        </th>
                        <td>
                            <input type="text" id="melli_terminal_id" name="melli_terminal_id" size="60" value="{$terminal_id}" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_Melli" {$style}>
                        <th scope="row" valign="top">
                            <label for="melli_terminal_key">کلید تراکنش:</label>
                        </th>
                        <td>
                            <input type="text" id="melli_terminal_key" name="melli_terminal_key" size="60" value="{$terminal_key}" />
                        </td>
                    </tr>
FORM;
                echo $form_fields;

            }

            public static function pmpro_checkout_before_change_membership_level($user_id, $morder)
            {
                global $wpdb, $discount_code_id;

                //if no order, no need to pay
                if (empty($morder)) {
                    return;
                }

                $morder->user_id = $user_id;
                $morder->saveOrder();

                //save discount code use
                if (!empty($discount_code_id)) {
                    $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");
                }

                //$morder->Gateway->sendToTwocheckout($morder);
                global $pmpro_currency;

                $gtw_env = pmpro_getOption('gateway_environment');

                $order_id = $morder->code;
                $redirect = admin_url('admin-ajax.php') . "?action=Melli-ins&oid=$order_id";

                global $pmpro_currency;

                $amount = intval($morder->subtotal);
                if ($pmpro_currency == 'IRT') {
                    $amount *= 10;
                }

                $terminal_id = pmpro_getOption('melli_terminal_id');
                $merchant_id = pmpro_getOption('melli_merchant_id');
                $terminal_key = pmpro_getOption('melli_terminal_key');
                $sign_data = self::sadad_encrypt($terminal_id . ';' . $morder->id . ';' . $amount, $terminal_key);

                $parameters = array(
                    'MerchantID' => $merchant_id,
                    'TerminalId' => $terminal_id,
                    'Amount' => $amount,
                    'OrderId' => $morder->id,
                    'LocalDateTime' => date('Ymdhis'),
                    'ReturnUrl' => $redirect,
                    'SignData' => $sign_data,
                );

                $error_flag = false;
                $error_msg = '';

                $result = self::sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

                if ($result != false) {
                    if ($result->ResCode == 0) {
                        $payment_url = 'https://sadad.shaparak.ir/VPG/Purchase?Token=' . $result->Token;
                        header("Location: {$payment_url}");
                        die;
                    } else {
                        //bank returned an error
                        $error_flag = true;
                        $error_msg = 'خطا در انتقال به بانک! ' . self::sadad_request_err_msg($result->ResCode);
                    }
                } else {
                    // couldn't connect to bank
                    $error_flag = true;
                    $error_msg = 'خطا! برقراری ارتباط با بانک امکان پذیر نیست.';
                }

                if ($error_flag) {
                    $morder->status = 'cancelled';
                    $morder->notes = __($error_msg, 'pmpro');
                    $morder->saveOrder();
                    wp_die($error_msg);
                }

            }

            public static function pmpro_wp_ajax_Melli_ins()
            {
                global $gateway_environment, $pmpro_msg, $pmpro_msgt;
                if (!isset($_GET['oid']) || is_null($_GET['oid'])) {
                    wp_die('پارامترهای ضروری ارسال نشده اند.');
                }

                if (!isset($_POST['token']) || !isset($_POST['OrderId']) || !isset($_POST['ResCode'])) {
                    wp_die('پارامترهای ضروری ارسال نشده اند.');
                }


                $oid = $_GET['oid'];

                $morder = null;
                try {
                    $morder = new MemberOrder($oid);
                    $morder->getMembershipLevel();
                    $morder->getUser();
                } catch (Exception $exception) {
                    wp_die('مقدار پارامترهای ارسالی معتبر نیست.');
                }

                $current_user_id = get_current_user_id();

                if ($current_user_id !== intval($morder->user_id)) {
                    wp_die('این خرید متعلق به شما نیست.');
                }

                $gtw_env = pmpro_getOption('gateway_environment');

                $token = $_POST['token'];
                //verify payment
                $parameters = array(
                    'Token' => $token,
                    'SignData' => self::sadad_encrypt($token, pmpro_getOption('melli_terminal_key')),
                );

                $error_flag = false;
                $error_msg = '';

                $result = self::sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);
                if ($result != false) {
                    if ($result->ResCode == 0) {
                        if (self::do_level_up($morder, $result->RetrivalRefNo)) {
                            header('Location:' . pmpro_url('confirmation', '?level=' . $morder->membership_level->id));
                        }
                    } else {
                        //couldn't verify the payment due to a back error
                        $error_flag = true;
                        $error_msg = 'خطا هنگام پرداخت! ' . self::sadad_verify_err_msg($result->ResCode);
                    }
                } else {
                    //couldn't verify the payment due to a connection failure to bank
                    $error_flag = true;
                    $error_msg = 'خطا! عدم امکان دریافت تاییدیه پرداخت از بانک';
                }
                if ($error_flag) {
                    $morder->status = 'cancelled';
                    $morder->notes = __($error_msg, 'pmpro');
                    $morder->saveOrder();
                    header('Location: ' . pmpro_url());
                    wp_die($error_msg);
                }
            }

            public static function do_level_up(&$morder, $transaction_id)
            {
                global $wpdb;
                //filter for level
                $morder->membership_level = apply_filters('pmpro_inshandler_level', $morder->membership_level, $morder->user_id);

                //fix expiration date
                if (!empty($morder->membership_level->expiration_number)) {
                    $enddate = "'" . date('Y-m-d', strtotime('+ ' . $morder->membership_level->expiration_number . ' ' . $morder->membership_level->expiration_period, current_time('timestamp'))) . "'";
                } else {
                    $enddate = 'NULL';
                }

                //get discount code
                $morder->getDiscountCode();
                if (!empty($morder->discount_code)) {
                    //update membership level
                    $morder->getMembershipLevel(true);
                    $discount_code_id = $morder->discount_code->id;
                } else {
                    $discount_code_id = '';
                }

                //set the start date to current_time('mysql') but allow filters
                $startdate = apply_filters('pmpro_checkout_start_date', "'" . current_time('mysql') . "'", $morder->user_id, $morder->membership_level);

                //custom level to change user to
                $custom_level = [
                    'user_id' => $morder->user_id,
                    'membership_id' => $morder->membership_level->id,
                    'code_id' => $discount_code_id,
                    'initial_payment' => $morder->membership_level->initial_payment,
                    'billing_amount' => $morder->membership_level->billing_amount,
                    'cycle_number' => $morder->membership_level->cycle_number,
                    'cycle_period' => $morder->membership_level->cycle_period,
                    'billing_limit' => $morder->membership_level->billing_limit,
                    'trial_amount' => $morder->membership_level->trial_amount,
                    'trial_limit' => $morder->membership_level->trial_limit,
                    'startdate' => $startdate,
                    'enddate' => $enddate,];

                global $pmpro_error;
                if (!empty($pmpro_error)) {
                    echo $pmpro_error;
                    inslog($pmpro_error);
                }

                if (pmpro_changeMembershipLevel($custom_level, $morder->user_id) !== false) {
                    //update order status and transaction ids
                    $morder->status = 'success';
                    $morder->payment_transaction_id = $transaction_id;
                    //if( $recurring )
                    //    $morder->subscription_transaction_id = $transaction_id;
                    //else
                    $morder->subscription_transaction_id = '';
                    $morder->saveOrder();

                    //add discount code use
                    if (!empty($discount_code) && !empty($use_discount_code)) {
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $morder->user_id . "', '" . $morder->id . "', '" . current_time('mysql') . "')");
                    }

                    //save first and last name fields
                    if (!empty($_POST['first_name'])) {
                        $old_firstname = get_user_meta($morder->user_id, 'first_name', true);
                        if (!empty($old_firstname)) {
                            update_user_meta($morder->user_id, 'first_name', $_POST['first_name']);
                        }
                    }
                    if (!empty($_POST['last_name'])) {
                        $old_lastname = get_user_meta($morder->user_id, 'last_name', true);
                        if (!empty($old_lastname)) {
                            update_user_meta($morder->user_id, 'last_name', $_POST['last_name']);
                        }
                    }

                    //hook
                    do_action('pmpro_after_checkout', $morder->user_id);

                    //setup some values for the emails
                    if (!empty($morder)) {
                        $invoice = new MemberOrder($morder->id);
                    } else {
                        $invoice = null;
                    }

                    //inslog("CHANGEMEMBERSHIPLEVEL: ORDER: " . var_export($morder, true) . "\n---\n");

                    $user = get_userdata(intval($morder->user_id));
                    if (empty($user)) {
                        return false;
                    }

                    $user->membership_level = $morder->membership_level;  //make sure they have the right level info
                    //send email to member
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutEmail($user, $invoice);

                    //send email to admin
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutAdminEmail($user, $invoice);

                    return true;
                } else {
                    return false;
                }
            }

            private
            static function sadad_encrypt($data, $secret)
            {
                $key = base64_decode($secret);
                $blockSize = mcrypt_get_block_size('tripledes', 'ecb');
                $len = strlen($data);
                $pad = $blockSize - ($len % $blockSize);
                $data .= str_repeat(chr($pad), $pad);
                $encData = mcrypt_encrypt('tripledes', $key, $data, 'ecb');

                return base64_encode($encData);
            }

            private
            static function sadad_call_api($url, $data = false)
            {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
                curl_setopt($ch, CURLOPT_POST, 1);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                $result = curl_exec($ch);
                curl_close($ch);
                return !empty($result) ? json_decode($result) : false;
            }

            private
            static function sadad_request_err_msg($err_code)
            {

                $message = 'در حین پرداخت خطای سیستمی رخ داده است .';
                switch ($err_code) {
                    case 3:
                        $message = 'پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.';
                        break;
                    case 23:
                        $message = 'پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.';
                        break;
                    case 58:
                        $message = 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.';
                        break;
                    case 61:
                        $message = 'مبلغ تراکنش از حد مجاز بالاتر است.';
                        break;
                    case 1000:
                        $message = 'ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.';
                        break;
                    case 1001:
                        $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.';
                        break;
                    case 1002:
                        $message = 'خطا در سیستم- تراکنش ناموفق';
                        break;
                    case 1003:
                        $message = 'آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.';
                        break;
                    case 1004:
                        $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.';
                        break;
                    case 1005:
                        $message = 'خطای دسترسی:لطفا بعدا تلاش فرمايید.';
                        break;
                    case 1006:
                        $message = 'خطا در سیستم';
                        break;
                    case 1011:
                        $message = 'درخواست تکراری- شماره سفارش تکراری می باشد.';
                        break;
                    case 1012:
                        $message = 'اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
                                   اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.';
                        break;
                    case 1015:
                        $message = 'پاسخ خطای نامشخص از سمت مرکز';
                        break;
                    case 1017:
                        $message = 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است';
                        break;
                    case 1018:
                        $message = 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید';
                        break;
                    case 1019:
                        $message = 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست';
                        break;
                    case 1020:
                        $message = 'پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد';
                        break;
                    case 1023:
                        $message = 'آدرس بازگشت پذيرنده نامعتبر است';
                        break;
                    case 1024:
                        $message = 'مهر زمانی پذيرنده نامعتبر است';
                        break;
                    case 1025:
                        $message = 'امضا تراکنش نامعتبر است';
                        break;
                    case 1026:
                        $message = 'شماره سفارش تراکنش نامعتبر است';
                        break;
                    case 1027:
                        $message = 'شماره پذيرنده نامعتبر است';
                        break;
                    case 1028:
                        $message = 'شماره ترمینال پذيرنده نامعتبر است';
                        break;
                    case 1029:
                        $message = 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                        break;
                    case 1030:
                        $message = 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                        break;
                    case 1031:
                        $message = 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .';
                        break;
                    case 1032:
                        $message = 'پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.';
                        break;
                    case 1033:
                        $message = 'به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
                                   است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.';
                        break;
                    case 1036:
                        $message = 'اطلاعات اضافی ارسال نشده يا دارای اشکال است';
                        break;
                    case 1037:
                        $message = 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد';
                        break;
                    case 1053:
                        $message = 'خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.';
                        break;
                    case 1055:
                        $message = 'مقدار غیرمجاز در ورود اطلاعات';
                        break;
                    case 1056:
                        $message = 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.';
                        break;
                    case 1058:
                        $message = 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.';
                        break;
                    case 1061:
                        $message = 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(';
                        break;
                    case 1064:
                        $message = 'لطفا مجددا سعی بفرمايید';
                        break;
                    case 1065:
                        $message = 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید';
                        break;
                    case 1066:
                        $message = 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است';
                        break;
                    case 1068:
                        $message = 'با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.';
                        break;
                    case 1072:
                        $message = 'خطا در پردازش پارامترهای اختیاری پذيرنده';
                        break;
                    case 1101:
                        $message = 'مبلغ تراکنش نامعتبر است';
                        break;
                    case 1103:
                        $message = 'توکن ارسالی نامعتبر است';
                        break;
                    case 1104:
                        $message = 'اطلاعات تسهیم صحیح نیست';
                        break;
                    default:
                        $message = 'خطای نامشخص';
                }
                return __($message, 'pmpro');
            }

            private
            static function sadad_verify_err_msg($res_code)
            {
                $error_text = '';
                switch ($res_code) {
                    case -1:
                    case '-1':
                        $error_text = 'پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.';
                        break;
                    case 101:
                    case '101':
                        $error_text = 'مهلت ارسال تراکنش به پايان رسیده است.';
                        break;
                }
                return __($error_text, 'pmpro');
            }

        }
    }
}
