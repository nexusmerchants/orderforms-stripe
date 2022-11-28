<?php

namespace WPCustomerPortalForStripe;

use Exception;
use Stripe\Collection;
use Stripe\Exception\ApiErrorException;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\StripeClient;
use Stripe\StripeObject;
use WP_User;

/**
 * Class Customer_Portal_For_Stripe_Shortcodes
 */
class Customer_Portal_For_Stripe_Stripe {
	/**
	 * @var StripeClient
	 */
	public StripeClient $stripeClient;

	/**
	 * Customer_Portal_For_Stripe_Stripe constructor.
	 */
	public function __construct() {
		Stripe::setAppInfo(
			"Customer Portal for Stripe WordPress Plugin",
			CUSTOMER_PORTAL_FOR_STRIPE_VERSION,
			"https://www.orderforms.com"
		);

		try {
			$this->stripeClient = new StripeClient( get_option( 'cpfs_stripe_secret_key', '' ) );
		} catch ( Exception $e ) {
			unset($this->stripeClient);
		}
	}

	/**
	 * @param  int|null  $userId
	 * @param  array|null  $expand
	 *
	 * @return Customer|StripeObject
	 * @throws ApiErrorException
	 */
	public function getOrCreateCustomer( int $userId = null, $expand = null ) {
        if (empty($this->stripeClient)) {
            wp_die(__("Could not create Stripe client. Did you set up your Stripe API keys in 'Settings > Stripe Customer Portal'?", CUSTOMER_PORTAL_FOR_STRIPE_PLUGIN_TEXTDOMAIN));
        }

		$stripeOptions = empty( $expand ) ? null : [ 'expand' => array_values( $expand ) ];
		$user          = empty( $userId ) ? wp_get_current_user() : get_user_by( 'id', $userId );

		$transientKey = 'cpfsStripeCustomer_' . $user->ID;
		$customer     = get_transient( $transientKey );

		if ( $customer === false ) {
			$cpfsStripeCustomerId = get_user_meta( $user->ID, 'cpfs_stripe_customer_id', true );

			if ( ! empty( $cpfsStripeCustomerId ) ) {
				$customer = $this->getCustomer( $cpfsStripeCustomerId, $stripeOptions );
			}

			if ( empty( $customer ) ) {
				$customer = $this->findCustomer( $user, $stripeOptions );
			}

			if ( empty( $customer ) || $customer->isDeleted() ) {
				$customer = $this->createCustomer( $user, $stripeOptions );
			}

			set_transient( $transientKey, $customer, 900 );
		}

		return $customer;
	}

	/**
	 * @param  string  $stripeCustomerId
	 * @param  array|null  $stripeOptions
	 *
	 * @return Customer
	 * @throws ApiErrorException
	 */
	public function getCustomer( string $stripeCustomerId, $stripeOptions = null ) {
		return $this->stripeClient->customers->retrieve( $stripeCustomerId, $stripeOptions );
	}

	/**
	 * @param  WP_User  $user
	 * @param  array|null  $stripeOptions
	 *
	 * @return Customer|null
	 * @throws ApiErrorException
	 */
	public function findCustomer( WP_User $user, $stripeOptions = null ) {
		$email     = strtolower( trim( $user->user_email ) );
		$customers = $this->stripeClient->customers->all( [
			'email' => $email,
			'limit' => 1,
		], $stripeOptions );

		if ( empty( $customers->data[0] ) ) {
			return null;
		}

		$customer = $customers->data[0];
		update_user_meta( $user->ID, 'cpfs_stripe_customer_id', $customer->id );

		return $customer;
	}

	/**
	 * @param  WP_User  $user
	 * @param  array|null  $stripeOptions
	 *
	 * @return Customer
	 * @throws ApiErrorException
	 */
	public function createCustomer( WP_User $user, $stripeOptions = null ) {
		$customer = $this->stripeClient->customers->create( [
			'email' => $user->user_email,
		], $stripeOptions );

		update_user_meta( $user->ID, 'cpfs_stripe_customer_id', $customer->id );

		return $customer;
	}

	/**
	 * @param Customer $customer
	 *
	 * @return SetupIntent
	 * @throws ApiErrorException
	 */
	public function createSetupIntent( Customer $customer ) {
		return $this->stripeClient->setupIntents->create( [
			'customer' => $customer->id
		] );
	}

	/**
	 * @param Customer $customer
	 *
	 * @return Collection
	 * @throws ApiErrorException
	 */
	public function getCards( Customer $customer ) {
		$transientKey = 'cpfsStripeCards_' . $customer->id;
		$data         = get_transient( $transientKey );

		if ( $data === false ) {
			$data = $this->stripeClient->paymentMethods->all(
				[
					'customer' => $customer->id,
					'type'     => 'card',
					// 'limit' => 10,
				]
			);

			set_transient( $transientKey, $data, 900 );
		}

		return $data;
	}

	/**
	 * @param Customer $customer
	 * @param  array|null  $stripeOptions
	 *
	 * @return Collection
	 * @throws ApiErrorException
	 */
	public function getInvoices( Customer $customer, $stripeOptions = null ) {
		$transientKey = 'cpfsStripeInvoices_' . $customer->id;
		$data         = get_transient( $transientKey );

		if ( $data === false ) {
			$data = $this->stripeClient->invoices->all(
				[
					'customer' => $customer->id,
					// 'limit' => 10,
				],
				$stripeOptions
			);

			set_transient( $transientKey, $data, 900 );
		}

		return $data;
	}

	/**
	 * @param Customer $customer
	 *
	 * @return Collection
	 * @throws ApiErrorException
	 */
	public function getSubscriptions( Customer $customer ) {
		$transientKey = 'cpfsStripeSubscriptions_' . $customer->id;
		$data         = get_transient( $transientKey );

		if ( $data === false ) {
			$subscriptions = $this->stripeClient->subscriptions->all(
				[
					'customer' => $customer->id,
					// 'limit' => 10,
				]
			);

			$data = [];
			foreach ( $subscriptions as $subscription ) {
				$data[] = $this->stripeClient->subscriptions->retrieve(
					$subscription->id,
					[
						'expand' => [
							'items.data.price.product'
						]
					]
				);
			}

			set_transient( $transientKey, $data, 900 );
		}

		return $data;
	}

	/**
	 * Cancel a Stripe subscription
	 */
	public function cancelSubscription() {
		$subscriptionId = sanitize_text_field( $_POST['subscriptionId'] );

		if ( empty( $subscriptionId ) ) {
			return wp_send_json_error( [
				'error' => 'Missing subscription ID',
			], 422 );
		}

		try {
			$subscription = $this->stripeClient->subscriptions->retrieve( $subscriptionId );
		} catch ( ApiErrorException $e ) {
			return wp_send_json_error( [
				'error' => $e->getMessage(),
			], 422 );
		}

		try {
			$response = $this->stripeClient->subscriptions->cancel( $subscriptionId );
		} catch ( ApiErrorException $e ) {
			return wp_send_json_error( [
				'error' => $e->getMessage(),
			], 422 );
		}

		$transientKey = 'cpfsStripeSubscriptions_' . $subscription->customer;
		delete_transient( $transientKey );

		return wp_send_json_success( [
			'status' => $response->status,
		] );
	}

	/**
	 * Set the default payment method
	 */
	public function setDefaultPaymentMethod() {
		$paymentMethod = sanitize_text_field( $_POST['paymentMethod'] );

		if ( empty( $paymentMethod ) ) {
			return wp_send_json_error( [
				'error' => 'Missing paymentMethod',
			], 422 );
		}

		global $cpfsStripe;
		$customer = $cpfsStripe->getOrCreateCustomer();
		$user = wp_get_current_user();

		try {
			$response = $this->stripeClient->customers->update(
				$customer->id,
				[
					'invoice_settings' => [
						'default_payment_method' => $paymentMethod,
					]
				]
			);
		} catch ( ApiErrorException $e ) {
			return wp_send_json_error( [
				'error' => $e->getMessage(),
			], 422 );
		}

		delete_transient( 'cpfsStripeCards_' . $customer->id );
		delete_transient( 'cpfsStripeCustomer_' . $user->ID );

		return wp_send_json_success( [
			'status' => 'success',
		] );
	}

	/**
	 * @param  int  $user_id
	 * @param  WP_User  $oldUserData
	 *
	 * @throws ApiErrorException
	 */
	public function updateCustomerEmailAddress( int $user_id, WP_User $oldUserData ) {
		global $cpfsStripe;
		$user     = get_userdata( $user_id );
		$oldEmail = strtolower( trim( $oldUserData->data->user_email ) );
		$newEmail = strtolower( trim( $user->user_email ) );

		if ( $newEmail !== $oldEmail ) {
			$customer = $cpfsStripe->getOrCreateCustomer( $user_id );

			try {
				$this->stripeClient->customers->update(
					$customer->id,
					[
						'email' => $newEmail,
					]
				);
			} catch ( ApiErrorException $e ) {
				throw $e;
			}

			$transientKey = 'cpfsStripeCustomer_' . $user->ID;
			delete_transient( $transientKey );
		}
	}
}
