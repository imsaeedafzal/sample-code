<?php
    //\Stripe\Stripe::setApiKey("sk_test_vMo45U6579ctwl1bT60CEwjt");
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY_LIVE);
    
    $token = (isset($_POST['tokenId'])) ? $_POST['tokenId'] : NULL;
    
    $propertyOwnerId = (isset($_POST['propertyOwnerId'])) ? $_POST['propertyOwnerId'] : NULL;
    $stripeAccountId = (isset($_POST['stripeAccountId'])) ? $_POST['stripeAccountId'] : NULL;
    
    $propertyId = (isset($_POST['propertyId'])) ? $_POST['propertyId'] : NULL;
    $roomId = (isset($_POST['roomId'])) ? $_POST['roomId'] : NULL;
    
    $currentUserId = (isset($_POST['currentUserId'])) ? $_POST['currentUserId'] : NULL;
    $customerId = $currentUserId;
    
    $checkin = (!empty($_POST['checkin'])) ? $_POST['checkin'] : NULL;
    $checkout = (!empty($_POST['checkout'])) ? $_POST['checkout'] : NULL;
    $adults = (!empty($_POST['adults'])) ? $_POST['adults'] : NULL;
    $kids = (!empty($_POST['kids'])) ? $_POST['kids'] : NULL;
    $infants = (!empty($_POST['infants'])) ? $_POST['infants'] : NULL;
    $pets = (!empty($_POST['pets'])) ? $_POST['pets'] : NULL;
    $price = (!empty($_POST['price'])) ? $_POST['price'] : NULL;
    $pricePerNight = $price;
    $numberOfNights = (!empty($_POST['numberOfNights'])) ? $_POST['numberOfNights'] : NULL;
    $totalAmount = (!empty($_POST['totalAmount'])) ? $_POST['totalAmount'] : NULL;
    $totalTaxableAmount = (!empty($_POST['totalTaxableAmount'])) ? $_POST['totalTaxableAmount'] : NULL;
    $totalAmountWithTax = (!empty($_POST['totalAmountWithTax'])) ? $_POST['totalAmountWithTax'] : NULL;
    
    $currencySign = (!empty($_POST['currencySign'])) ? $_POST['currencySign'] : NULL;
    $currencyCode = (!empty($_POST['currencyCode'])) ? $_POST['currencyCode'] : NULL;
    $bookingType = (!empty($_POST['bookingType'])) ? $_POST['bookingType'] : NULL; // checkout
    
    $firstName = (!empty($_POST['bookingFirstName'])) ? $_POST['bookingFirstName'] : NULL;
    $lastName = (!empty($_POST['bookingLastName'])) ? $_POST['bookingLastName'] : NULL;
    $fullName = $firstName . ' ' . $lastName; // for username only
    $email = (isset($_POST['paymentEmail'])) ? $_POST['paymentEmail'] : NULL;
    $password = (isset($_POST['user_password'])) ? $_POST['user_password'] : drupal_base64_encode('WelcomeToTo!@#');
    
    $userType = (!empty($_POST['type'])) ? $_POST['type'] : 1; // customer
    $profileType = ($userType == 2) ? 'Property Owner' : 'Guest';
    $userRole = ($userType == 2) ? 'Property Owner' : 'Customer';
    $paymentMethod = 'Stripe';
    
    try {
        
        // Workflow: Step 1
        // Check if user is already logged in.
        // Skip the rest of automated process if logged in.
        $isAlreadyLoggedIn = false;
        if (!empty($currentUserId)) {
            $isUserExists = user_load($currentUserId);
            if (!empty($isUserExists)) {
                if ($isUserExists->uid == $currentUserId) {
                    $isAlreadyLoggedIn = true;
                    $customerId = $currentUserId; // Customer ID to use in booking
                }
            }
        }
        
        // Workflow: Step 2
        // Create new user if not already logged in and doesn't exists
        // Auto login the new user including sending him the password in email and login
        // Otherwise, ask user to provide different email address if exists or login to continue as well
        // TODO: Redirect user back on checkout if login from this page as well
        if (empty($currentUserId) || !$isAlreadyLoggedIn) {
            $isUserExists = user_load_by_mail($email);
            if (empty($isUserExists)) {
                
                // Create new user to auto login
//                $password = drupal_base64_encode('WelcomeToTo!@#');
                $hasNewUserCreated = toto_create_new_user($firstName, $lastName, $fullName, $password, $email, $profileType, $userRole, false);
                
                $alertMessage = 'Hey ' . $firstName . ', Welcome to Toto.';
                toto_set_alert('success', 'Welcome to Toto ' . $firstName, $alertMessage, $hasNewUserCreated['uid']);
                
                // Auto login
                if ($hasNewUserCreated['status'] == 200) {
                    // Activate user account
                    $getNewUser = user_load($hasNewUserCreated['uid']);
                    $newUserUsername = $getNewUser->name;
                    $getNewUser->status = NODE_PUBLISHED;
                    $is = user_save($getNewUser);
                    
                    // Auto login
                    $requestedUserId = toto_property_hooks_user_login_credentials($newUserUsername, $password);
                    if ($requestedUserId) {
                        global $user;
                        $user = user_load_by_name($newUserUsername);
                        drupal_session_regenerate();
                        $isAlreadyLoggedIn = true;
                        
                        $customerId = $user->uid;
                        
                        // TODO: Send password to newly create user and redirect back to checkout page
                        // Notify user
                        $accountActivateLink = toto_site_root() . 'activate?id=' . $customerId . '&key=' . md5($customerId);
                        $attributes = [
                            '@toto:name' => $fullName,
                            '@toto:username' => $email,
                            '@toto:email' => $email,
                            '@toto:password' => $password,
                            '@toto:type' => $profileType,
                            '@toto:activate' => $accountActivateLink,
                        ];
                        
                        $userEmail = $user->mail;
                        $userName = $fullName;
                        $emailType = 'new-user-via-checkout';
                        toto_execute_send_mail($emailType, $attributes, $userEmail, $userName);
                        
                        $results = [
                            'status' => 200,
                            'title' => 'Payment Charged Successfully. Creating booking..',
                            'message' => 'Please wait while creating the booking.'
                        ];
                    } else {
                        throw new Exception('Invalid username and password.');
                    }
                } else {
                    throw new Exception('Email address already exists. Please login to continue.');
                }
            } else {
                throw new Exception('Email address already exists. Please login to continue.');
            }
        }
        
        // Workflow: Step 3
        // Finally, charge Stripe payment to create booking
        if ($isAlreadyLoggedIn) {
            
            // Create customer in Stripe
            $stripeCustomer = \Stripe\Customer::create(array(
                'email' => $email,
                'source' => $token
            ));
            
            // Charge the payment from Stripe
            $stripeAmountCharged = $totalAmountWithTax * 100; // TODO: Discuss with Vicki if the total amount with tax will be charged in Stripe
            $application_fee = intval($stripeAmountCharged * 0.1);  // TODO: Discuss with Vicki is the 10% of commission will be charged on with taxable total amount
            $stripeCharge = \Stripe\Charge::create(array(
                'customer' => $stripeCustomer->id,
                'amount' => $stripeAmountCharged,
                'currency' => $currencyCode,
                'destination' => $stripeAccountId, // property owner Stripe account ID
                'application_fee' => $application_fee
            ));
            
            // Create booking once the payment is charged from Stripe
            if ($stripeCharge) {
                
                // Create booking
                $stripeIsPaid = ($stripeCharge) ? 'Paid' : 'Unpaid';
                
                //$paidAmountWithoutGst = $stripeAmountCharged;
                //$gstAmount = $stripeAmountCharged - ($stripeAmountCharged/1.1); // GST
                //$paidAmount = ($stripeAmountCharged + $gstAmount);
                
                $paidAmount = $totalAmountWithTax;
                $remainingAmount = ($totalAmountWithTax - $paidAmount);
                // TODO: Discuss these amounts with Vicki to make calculation accordingly
                $discount = 0;
                $chargeFee = ($totalAmount * 10) / 100; // 10% Commission Fee
                $otherFee = 0;
                $taxFee = $totalTaxableAmount; // Pre-tax
                
                $arrBookingCreated = _create_booking($stripeCustomer, $stripeCharge, $stripeIsPaid,
                    $customerId, $propertyId, $roomId, $checkin, $checkout,
                    $adults, $kids, $infants, $pets, $numberOfNights, $pricePerNight,
                    $totalAmountWithTax, $totalAmountWithTax, $remainingAmount);
                
                // Update total reserved room capacity
                $propertyRoom = $arrBookingCreated['room'];
                $totalRoomCapacity = (!empty($propertyRoom->field_room_capacity['und'][0]['value'])) ? $propertyRoom->field_room_capacity['und'][0]['value'] : 1;
                $reservedRoomCapacity = (!empty($propertyRoom->field_room_capacity_reserved['und'][0]['value'])) ? $propertyRoom->field_room_capacity_reserved['und'][0]['value'] : 1;
                
                // Make all booking date reserved
                // TODO: Do not reserve the date when booking type is Multiple
                // TODO: Reserve based on the remaining capacity. e.g.: total - reserved = remaining
                $arrBookingDateRange = toto_date_range($checkin, $checkout);
                $totalPrice = 0;
                $arrAvailablePrices = [];
                foreach ($arrBookingDateRange as $d) {
                    $available = toto_get_room_availability_by_date($propertyId, $roomId, $d);
                    $available = reset($available);
                    if (!empty($available)) {
                        if ($totalRoomCapacity > 1) {
                            $reservedRoomCapacity += 1;
                        } else {
                            $reservedRoomCapacity = 1;
                        }
                        
                        // Update room reserved capacity
                        $collection = entity_metadata_wrapper('field_collection_item', $roomId);
                        $collection->field_room_capacity_reserved->set($reservedRoomCapacity);
                        $collection->save();
                        
                        // Update room capacity of that day
                        $availableDateCapacity = $available->field_availability_capacity['und'][0]['value'];
                        $availableDateCapacityReserved = $available->field_availability_capacity_rese['und'][0]['value'];
                        $availableDateCapacityReserved += 1;
                        
                        $available->field_availability_is_reserved['und'][0]['value'] = true;
                        $available->field_availability_capacity['und'][0]['value'] = ($availableDateCapacity - 1);
                        $available->field_availability_capacity_rese['und'][0]['value'] = $availableDateCapacityReserved;
                        node_save($available);
                    }
                }
                
                $stripeCreated = $stripeCharge->created;
                $bookingCreated = $arrBookingCreated['booking'];
                
                $propertyOwnerObj = $arrBookingCreated['propertyOwner'];
                $propertyOwnerName = $arrBookingCreated['propertyOwnerName'];
                $propertyOwnerEmail = $arrBookingCreated['propertyOwnerEmail'];
                $customerObj = $arrBookingCreated['customer'];
                $customerName = $arrBookingCreated['customerName'];
                $customerEmail = $arrBookingCreated['customerEmail'];
                $propertyName = $arrBookingCreated['propertyName'];
                $roomName = $arrBookingCreated['roomName'];
                $roomDescription = $arrBookingCreated['roomDescription'];
                $locationName = $arrBookingCreated['locationName'];
                
                $bookingPaymentDate = date('M d Y', $stripeCreated);
                $bookingPaymentTime = date('H:i:s a', $stripeCreated);
                
                // Booking Confirmation to User/Customer
                $attributes = _get_booking_attributes(
                    $propertyOwnerObj, $customerObj,
                    $customerName, $customerEmail, $currencySign, $currency,
                    $bookingCreated, $bookingPaymentDate, $bookingPaymentTime,
                    $propertyName, $roomName, $roomDescription, $propertyAddress, $locationName, $propertyPolicy,
                    $adults, $kids, $infants, $pets, $checkin, $checkout,
                    $numberOfNights, $pricePerNight, $totalAmountWithTax, $totalAmount, $totalAmountWithTax, $remainingAmount, $discount, $chargeFee, $otherFee, $taxFee,
                    $paymentMethod, $stripeBookingStatus);
                
                toto_execute_send_mail('booking-notification-guest', $attributes, $customerEmail, $customerName);
                toto_execute_send_mail('guest-booking-confirmation-and-tax-invoice', $attributes, $customerEmail, $customerName);
                
                // Booking Notification to Property Owner
                // TODO: this needs to be 'Property Owner' email and name in REAL time once production is approved
                //$_propertyOwnerName = toto_get_sendmail_from_name();
                $_propertyOwnerName = $propertyOwnerName;
                //$_propertyOwnerEmail = toto_get_sendmail_from();
                $_propertyOwnerEmail = $propertyOwnerEmail;
                
                $attributes = _get_booking_attributes(
                    $propertyOwnerObj, $customerObj,
                    $_propertyOwnerName, $_propertyOwnerEmail, $currencySign, $currency,
                    $bookingCreated, $bookingPaymentDate, $bookingPaymentTime,
                    $propertyName, $roomName, $roomDescription, $propertyAddress, $locationName, $propertyPolicy,
                    $adults, $kids, $infants, $pets, $checkin, $checkout,
                    $numberOfNights, $pricePerNight, $totalAmountWithTax, $totalAmount, $totalAmountWithTax, $remainingAmount, $discount, $chargeFee, $otherFee, $taxFee,
                    $paymentMethod, $stripeBookingStatus);
                
                toto_execute_send_mail('booking-notification-property-owner', $attributes, $_propertyOwnerEmail, $_propertyOwnerName);
                toto_execute_send_mail('booking-confirmation-and-invoice', $attributes, $_propertyOwnerEmail, $_propertyOwnerName);
                toto_execute_send_mail('booking-confirmation-and-rcti', $attributes, $_propertyOwnerEmail, $_propertyOwnerName);
                
                $redirectUrl = 'dashboard';
                foreach ($objCustomer->roles as $r) {
                    if ($r == 'Property Owner') {
                        $redirectUrl = 'booking-system';
                    }
                }
                
                $results = [
                    'url' => $redirectUrl,
                    'bookingId' => $bookingCreated->nid,
                    'status' => 200,
                    'title' => 'Booking Created Successfully',
                    'message' => "Please wait while it's loading...",
                ];
                
                // Log for customer
                $alertMessage = 'Success! The booking #' . $bookingCreated->nid . ' has been created for ' . $propertyName;
                toto_set_alert('success', 'Booking Created Successfully!', $alertMessage, $customerObj->uid);
                
                // Log for property owner
                $alertMessage = 'Success! ' . $customerName . ' has created the booking for ' . $propertyName;
                toto_set_alert('success', 'Created new booking #' . $bookingCreated->nid, $alertMessage, $propertyOwnerId);
            }
        } else {
            throw new Exception('Please provide valid card information.');
        }
        
    } catch (Exception $exception) {
        $results = [
            'status' => 400,
            'title' => 'Error! ' . $exception->getMessage(),
            'message' => $exception->getMessage()
        ];
    }
    
    /*
     *  _createBooking()
     * - Create the booking for customers
     * TODO: Remove field_bs_is_commission_paid field and condition from page--manage-accounts.tpl.php as we have implemented Stripe Connect
     */
    function _create_booking($stripeCustomer, $stripeCharge, $stripeIsPaid,
                             $customerId, $propertyId, $roomId, $checkin, $checkout,
                             $adults, $kids, $infants, $pets, $numberOfNights, $pricePerNight,
                             $totalAmount, $totalAmountPaid, $remainingAmount)
    {
        
        $stripeCreated = $stripeCharge->created;
        $stripeTransactionId = $stripeCharge->id;
        $stripeCustomerId = $stripeCustomer->id;
        $stripeEmail = $stripeCustomer->email;
        
        // Get Property
        $getProperty = node_load($propertyId);
        
        // Get Room (fields collection)
        $getRoom = toto_get_collection($roomId);
        $getRoom = reset($getRoom);
        
        $propertyName = $getProperty->title;
        $roomName = $getRoom->field_room_name['und'][0]['value'];
        $roomDescription = $getRoom->field_room_description['und'][0]['value'];
        
        $locationName = $roomName;
        if (!empty($getProperty->field_property_location_name['und'][0]['tid'])) {
            $locationTid = $getProperty->field_property_location_name['und'][0]['tid'];
            $termLocation = taxonomy_term_load($locationTid);
            if (!empty($termLocation->tid)) {
                $locationName = $termLocation->name;
            }
        }
        
        $propertyAddress = '';
        if (!empty($getProperty->field_property_address_line_1['und'][0]['value'])) {
            $propertyAddress = $getProperty->field_property_address_line_1['und'][0]['value'];
        }
        if (!empty($getProperty->field_property_address_line_2['und'][0]['value'])) {
            $propertyAddress .= '<br>';
            $propertyAddress .= $getProperty->field_property_address_line_2['und'][0]['value'];
        }
        
        $propertyPolicy = '';
        if (!empty($getProperty->field_property_policies['und'][0]['value'])) {
            $propertyPolicy = $getProperty->field_property_policies['und'][0]['value'];
        }
        
        // Property owner
        $objPropertyOwner = user_load($getProperty->uid);
        $propertyOwnerId = $objPropertyOwner->uid;
        $propertyOwnerName = $objPropertyOwner->field_user_first_name['und'][0]['value'] . ' ' . $objPropertyOwner->field_user_last_name['und'][0]['value'];
        $propertyOwnerEmail = $objPropertyOwner->mail;
        
        // Customer
        $objCustomer = user_load($customerId);
        $customerName = $objCustomer->field_user_first_name['und'][0]['value'] . ' ' . $objCustomer->field_user_last_name['und'][0]['value'];
        $customerEmail = $objCustomer->mail;
        
        $paymentMethod = 'Stripe';
        $bookingStatus = ['Reservation', 'Confirmed', 'Cancelled', 'Completed'];
        $stripeBookingStatus = ($stripeIsPaid == 'Paid') ? $bookingStatus[1] : $bookingStatus[0];
        
        // Create Booking
        $bookingTitle = 'Order from ' . ucwords($objCustomer->name) . ' (' . $checkin . ' - ' . $checkout . ')';
        $stripeCustomerObjSerialized = json_encode($stripeCustomer); // Stripe customer object serialized
        $stripeChargeObjSerialized = json_encode($stripeCharge); // Stripe charge object serialized
        
        $bookingCreated = petfriendly_create_booking($bookingTitle, $propertyId, $roomId, $propertyOwnerId, $customerId,
            $checkin, $checkout,
            $adults, $kids, $infants, $pets, $numberOfNights, $pricePerNight,
            $totalAmount, $totalAmountPaid, $remainingAmount,
            $stripeBookingStatus, $stripeIsPaid,
            $stripeCreated, $stripeTransactionId, $stripeCustomerId, $stripeEmail, $stripeCustomerObjSerialized, $stripeChargeObjSerialized);
        
        return [
            'propertyOwner' => $objPropertyOwner,
            'propertyOwnerName' => $propertyOwnerName,
            'propertyOwnerEmail' => $propertyOwnerEmail,
            'customer' => $objCustomer,
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'propertyName' => $propertyName,
            'room' => $getRoom,
            'roomName' => $roomName,
            'roomDescription' => $roomDescription,
            'locationName' => $locationName,
            'booking' => $bookingCreated,
        ];
    }
    
    /*
     *  _get_booking_attributes()
     * - Returns the email parameters with results
     */
    function _get_booking_attributes($propertyOwnerObj, $customerObj, $name, $email, $currencySign, $currency,
                                     $bookingCreated, $bookingPaymentDate, $bookingPaymentTime,
                                     $propertyName, $roomName, $roomDescription, $propertyAddress, $locationName, $propertyPolicy,
                                     $adults, $kids, $infants, $pets, $checkin, $checkout,
                                     $numberOfNights, $pricePerNight, $totalAmountWithTax, $totalAmountWithoutTax, $paidAmount, $remainingAmount, $discount, $chargeFee, $otherFee, $taxFee,
                                     $paymentMethod, $stripeBookingStatus)
    {
        
        $bookingDateCreated = date('d/M/Y H:i:s', $bookingCreated->created);
        //$encodeBookingId = drupal_base64_encode($bookingCreated->nid);
        $bookingInvoiceNumber = date('ymdHi');
        
        $linkTerms = toto_get_domain() . '/' . '/terms';
        $linkPolicy = toto_get_domain() . '/' . '/privacy';
        
        $propertyPolicyLink = $linkPolicy;
        
        $guestAddress = (!empty($customerObj->field_user_addres['und'][0]['value'])) ? $customerObj->field_user_addres['und'][0]['value'] : '';
        
        // Property owner business or legal information
        $businessName = (!empty($propertyOwnerObj->field_user_bus_legal_name['und'][0]['value'])) ? $propertyOwnerObj->field_user_bus_legal_name['und'][0]['value'] : '';
        $businessAddress = (!empty($propertyOwnerObj->field_user_bus_address['und'][0]['value'])) ? $propertyOwnerObj->field_user_bus_address['und'][0]['value'] : '';
        $propertyIndividualLegalName = (!empty($propertyOwnerObj->field_user_indv_legal_name['und'][0]['value'])) ? $propertyOwnerObj->field_user_indv_legal_name['und'][0]['value'] : '';
        $propertyIndividualLegalAddress = (!empty($propertyOwnerObj->field_user_indv_legal_address['und'][0]['value'])) ? $propertyOwnerObj->field_user_indv_legal_address['und'][0]['value'] : '';
        
        $propertyOwnerLegalName = (!empty($businessName)) ? $businessName : $propertyIndividualLegalName;
        $propertyOwnerLegalAddress = (!empty($businessAddress)) ? $businessName : $propertyIndividualLegalAddress;
        
        if (!empty($propertyOwnerObj->field_user_bus_address['und'][0]['value'])) {
            $propertyOwnerProfileFullAddress = $propertyOwnerObj->field_user_bus_address['und'][0]['value'];
        } else {
            $propertyOwnerProfileFullAddress = $propertyOwnerObj->field_user_indv_legal_address['und'][0]['value'];
        }
        
        $propertyOwnerProfileAbn = (!empty($propertyOwnerObj->field_user_buss_abn['und'][0]['value'])) ? $propertyOwnerObj->field_user_buss_abn['und'][0]['value'] : '';
        
        if (!empty($propertyOwnerObj->field_user_bus_legal_name['und'][0]['value'])) {
            $propertyOwnerProfileBusinessName = $propertyOwnerObj->field_user_bus_legal_name['und'][0]['value'];
        } else {
            $propertyOwnerProfileBusinessName = $propertyOwnerObj->field_user_indv_legal_name['und'][0]['value'];
        }
        
        
        //$gstAmount = $paidAmount - ($paidAmount/1.1); // GST
        //$paidAmountWithGst = ($paidAmount + $gstAmount);
        
        $attributes = [
            '@toto:booking_date_created' => $bookingDateCreated,
            '@toto:username' => ucwords($email),
            '@toto:property_owner_legal_name' => $propertyOwnerLegalName,
            '@toto:property_owner_legal_address' => $propertyOwnerLegalAddress,
            '@toto:name' => ucwords($name),
            '@toto:booking_guest_name' => ucwords($name),
            '@toto:booking_owner_address' => $guestAddress,
            '@toto:booking_invoice' => $bookingInvoiceNumber,
            '@toto:booking_code' => $bookingCreated->nid,
            '@toto:booking_location_name' => $locationName,
            '@toto:booking_property_name' => $propertyName,
            '@toto:booking_room_name' => $roomName,
            '@toto:booking_property_address' => $propertyAddress,
            '@toto:booking_guests' => (($adults + $kids) + $infants),
            '@toto:booking_adults' => $adults,
            '@toto:booking_kids' => $kids,
            '@toto:booking_infants' => $infants,
            '@toto:booking_pets' => $pets,
            '@toto:booking_date_arrive' => $checkin,
            '@toto:booking_date_leave' => $checkout,
            '@toto:booking_property_special_information' => $propertyPolicy,
            '@toto:booking_room_description' => $roomDescription,
            '@toto:booking_number_of_nights' => $numberOfNights,
            '@toto:booking_price_per_night' => $currencySign . $pricePerNight,
            '@toto:booking_total_before_tax' => $currencySign . $totalAmountWithoutTax,
            '@toto:booking_charge_fee' => $chargeFee,
            '@toto:booking_commission_fee' => $chargeFee,
            '@toto:booking_other_fee' => $otherFee,
            '@toto:booking_tax_fee' => $taxFee,
            '@toto:booking_total_with_tax' => $currencySign . $totalAmountWithTax,
            '@toto:booking_payment_method' => $paymentMethod,
            '@toto:booking_payment_date' => $bookingPaymentDate,
            '@toto:booking_payment_time' => $bookingPaymentTime,
            '@toto:booking_link_terms_conditions' => $linkTerms,
            '@toto:booking_link_policy' => $linkPolicy,
            '@toto:booking_property_policy' => $propertyPolicyLink,
            '@toto:total_amount' => $currencySign . $paidAmount,
            '@toto:discount_amount' => $currencySign . $discount,
            '@toto:paid_amount' => $currencySign . $paidAmount,
            '@toto:remaining_amount' => $currencySign . $remainingAmount,
            '@toto:booking_status' => $stripeBookingStatus,
            '@toto:booking_owner_legal_address' => $propertyOwnerProfileFullAddress,
            '@toto:booking_owner_abn' => $propertyOwnerProfileAbn,
            '@toto:booking_owner_business_name' => $propertyOwnerProfileBusinessName,
        ];
        return $attributes;
    }
    
    /*
     *   _create_booking_webform()
     *  - Helps to insert the booking entry into the webform
     */
    function _create_booking_webform($propertyOwner, $propertyEmail, $customerName, $customerEmail,
                                     $propertyName, $roomName, $checkin, $checkout,
                                     $adults, $kids, $infants, $pets,
                                     $currency, $totalAmount, $paidAmount, $remainingAmount,
                                     $stripeBookingStatus, $bookingWebFormId = 43)
    {
        $data = [
            10 => [$propertyOwner],
            19 => [$propertyEmail],
            
            11 => [$customerName],
            20 => [$customerEmail],
            
            17 => [$propertyName],
            18 => [$roomName],
            6 => [$checkin],
            7 => [$checkout],
            
            9 => [$adults],
            21 => [$kids],
            22 => [$infants],
            4 => [$pets],
            
            13 => [$currency],
            12 => [$totalAmount],
            14 => [$paidAmount],
            15 => [$remainingAmount],
            16 => [$stripeBookingStatus],
        ];
        
        //$wf = petfriendly_get_form_submission($bookingWebFormId, 153);
        petfriendly_webform_insert_or_update($bookingWebFormId, $data);
    }
