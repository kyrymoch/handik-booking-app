<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Plugin {
	/**
	 * @var Handik_Booking_App_Plugin|null
	 */
	protected static $instance = null;

	public $settings;
	public $logger;
	public $contacts;
	public $addresses;
	public $job_requests;
	public $bookings;
	public $messages;
	public $cascade_delete;
	public $auth;
	public $routing;
	public $cal;
	public $photo_analysis;
	public $chatkit;
	public $updater;
	public $webhook;
	public $appearance;
	public $service_catalog;
	public $changelog;
	public $app_state;
	public $app_schema;
	public $upload_service;
	public $app_controller;
	public $assets;
	public $frontend_app;
	public $shortcode;
	public $rest_api;
	public $admin;
	public $widget_registry;
	public $cal_api;
	public $booking_presets;
	public $direct_booking;
	public $project_schedule;
	public $forms_rest_api;
	public $forms_router;
	public $admin_additional_forms;
	public $notifications;

	/**
	 * @return Handik_Booking_App_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		$migrations = new Handik_Booking_App_Migrations();
		$migrations->migrate();

		$this->settings       = new Handik_Booking_App_Settings();
		$this->logger         = new Handik_Booking_App_Logger( $this->settings );
		$this->contacts       = new Handik_Booking_App_Contacts_Service( $this->logger );
		$this->addresses      = new Handik_Booking_App_Addresses_Service();
		$this->job_requests   = new Handik_Booking_App_Job_Requests_Service( $this->logger );
		$this->bookings       = new Handik_Booking_App_Bookings_Service( $this->logger, $this->job_requests );
		$this->messages       = new Handik_Booking_App_Messages_Service( $this->logger );
		// Sprint 12 — cascading hard-delete coordinator. Wires every
		// data-layer service so the REST handlers can call one method
		// per entity (contact / request / booking) and the order-of-
		// operations stays in one place.
		$this->cascade_delete = new Handik_Booking_App_Cascade_Delete_Service(
			$this->contacts,
			$this->addresses,
			$this->job_requests,
			$this->bookings,
			$this->messages,
			$this->logger
		);
		$this->auth           = new Handik_Booking_App_Auth_Service( $this->settings, $this->logger, $this->contacts, $this->addresses, $this->job_requests );
		$this->routing        = new Handik_Booking_App_Routing_Service();
		$this->cal            = new Handik_Booking_App_Cal_Service( $this->settings, $this->job_requests, $this->contacts, $this->logger );
		$this->photo_analysis = new Handik_Booking_App_Photo_Analysis_Service( $this->settings, $this->logger, $this->job_requests );
		$this->chatkit        = new Handik_Booking_App_ChatKit_Service( $this->settings, $this->logger, $this->job_requests, $this->routing, $this->cal, $this->photo_analysis );
		$this->updater        = new Handik_Booking_App_Updater_Service( $this->settings, $this->logger );
		$this->appearance     = new Handik_Booking_App_Appearance_Service( $this->settings );

		// Additional Forms module — depends on $this->appearance for design tokens
		// passed to the public SPA, and is constructed before $this->webhook so
		// the webhook can route Cal events by metadata.handik_booking_source.
		$this->cal_api          = new Handik_Booking_App_Cal_Api_Service( $this->settings, $this->logger );
		$this->booking_presets  = new Handik_Booking_App_Booking_Presets_Service( $this->logger );
		$this->direct_booking   = new Handik_Booking_App_Direct_Booking_Service( $this->booking_presets, $this->contacts, $this->addresses, $this->settings, $this->logger, $this->bookings );
		$this->project_schedule = new Handik_Booking_App_Project_Schedule_Service( $this->booking_presets, $this->cal_api, $this->contacts, $this->addresses, $this->logger );
		$this->forms_rest_api   = new Handik_Booking_App_Forms_Rest_Api( $this->booking_presets, $this->direct_booking, $this->project_schedule, $this->logger );
		$this->forms_router     = new Handik_Booking_App_Forms_Router( $this->booking_presets, $this->project_schedule, $this->settings, $this->appearance );
		$this->admin_additional_forms = new Handik_Booking_App_Admin_Additional_Forms( $this->booking_presets, $this->direct_booking, $this->project_schedule, $this->contacts, $this->addresses );

		$this->webhook        = new Handik_Booking_App_Webhook_Service( $this->settings, $this->logger, $this->job_requests, $this->bookings, $this->direct_booking, $this->project_schedule );

		// Sprint 14a — Notifications_Service subscribes to the new
		// `handik_booking_confirmed` action that the three booking-creation
		// sites (Cal upsert, direct capture, project confirm_schedule) fire.
		// Constructor registers the action listener; nothing else needs DI.
		$this->notifications  = new Handik_Booking_App_Notifications_Service( $this->settings, $this->logger );
		$this->service_catalog = new Handik_Booking_App_Service_Catalog_Service( $this->settings );
		$this->changelog      = new Handik_Booking_App_Changelog_Service();
		$this->app_state      = new Handik_Booking_App_State( $this->service_catalog );
		$this->app_schema     = new Handik_Booking_App_Schema();
		$this->upload_service = new Handik_Booking_App_Upload_Service( $this->contacts );
		$this->app_controller = new Handik_Booking_App_Controller( $this->app_state, $this->app_schema, $this->upload_service, $this->settings, $this->appearance, $this->auth, $this->contacts, $this->addresses, $this->job_requests, $this->bookings, $this->routing, $this->cal, $this->changelog );
		$this->assets         = new Handik_Booking_App_Assets( $this->appearance, $this->settings );
		$this->frontend_app   = new Handik_Booking_App_Frontend_App( $this->assets, $this->appearance );
		$this->shortcode      = new Handik_Booking_App_Shortcode( $this->frontend_app );
		$this->rest_api       = new Handik_Booking_App_REST_API( $this->app_controller, $this->auth, $this->chatkit, $this->webhook, $this->messages, $this->bookings, $this->contacts, $this->addresses, $this->settings, $this->logger, $this->job_requests, $this->service_catalog, $this->cascade_delete, $this->direct_booking, $this->booking_presets );
		$this->admin          = new Handik_Booking_App_Admin( $this->settings, $this->assets, $this->contacts, $this->addresses, $this->job_requests, $this->bookings, $this->logger, $this->changelog, $this->service_catalog, $this->messages, $this->admin_additional_forms, $this->booking_presets );
		$this->widget_registry = new Handik_Booking_App_Widget_Registry();

		add_action( 'template_redirect', array( $this, 'maybe_process_magic_link' ) );
	}

	public function maybe_process_magic_link() {
		$this->auth->maybe_process_magic_link();
	}
}
