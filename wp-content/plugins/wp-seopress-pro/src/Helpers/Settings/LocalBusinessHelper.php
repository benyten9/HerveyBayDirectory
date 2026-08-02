<?php

namespace SEOPressPro\Helpers\Settings;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

abstract class LocalBusinessHelper {
	public static function getListTypes() {
		$types = array(
			array(
				'value' => 'LocalBusiness',
				'label' => __( 'Local Business (default)', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AnimalShelter',
				'label' => __( 'Animal Shelter', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutomotiveBusiness',
				'label' => __( 'Automotive Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoBodyShop',
				'label' => __( '|-Auto Body Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoDealer',
				'label' => __( '|-Auto Dealer', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoPartsStore',
				'label' => __( '|-Auto Parts Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoRental',
				'label' => __( '|-Auto Rental', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoRepair',
				'label' => __( '|-Auto Repair', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Auto Wash',
				'label' => __( '|-AutoWash', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'GasStation',
				'label' => __( '|-Gas Station', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MotorcycleDealer',
				'label' => __( '|-Motorcycle Dealer', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MotorcycleRepair',
				'label' => __( '|-Motorcycle Repair', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ChildCare',
				'label' => __( 'Child Care', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'DryCleaningOrLaundry',
				'label' => __( 'Dry Cleaning Or Laundry', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'EmergencyService',
				'label' => __( 'Emergency Service', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'FireStation',
				'label' => __( '|-Fire Station', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Hospital',
				'label' => __( '|-Hospital', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PoliceStation',
				'label' => __( '|-Police Station', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'EmploymentAgency',
				'label' => __( 'Employment Agency', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'EntertainmentBusiness',
				'label' => __( 'Entertainment Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AdultEntertainment',
				'label' => __( '|-Adult Entertainment', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AmusementPark',
				'label' => __( '|-Amusement Park', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ArtGallery',
				'label' => __( '|-Art Gallery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Casino',
				'label' => __( '|-Casino', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ComedyClub',
				'label' => __( '|-Comedy Club', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MovieTheater',
				'label' => __( '|-Movie Theater', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'NightClub',
				'label' => __( '|-Night Club', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'FinancialService',
				'label' => __( 'Financial Service', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AccountingService',
				'label' => __( '|-Accounting Service', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutomatedTeller',
				'label' => __( '|-Automated Teller', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BankOrCreditUnion',
				'label' => __( '|-Bank Or Credit Union', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'InsuranceAgency',
				'label' => __( '|-Insurance Agency', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'FoodEstablishment',
				'label' => __( 'Food Establishment', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Bakery',
				'label' => __( '|-Bakery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BarOrPub',
				'label' => __( '|-Bar Or Pub', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Brewery',
				'label' => __( '|-Brewery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'CafeOrCoffeeShop',
				'label' => __( '|-Cafe Or Coffee Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'FastFoodRestaurant',
				'label' => __( '|-Fast Food Restaurant', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'IceCreamShop',
				'label' => __( '|-Ice Cream Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Restaurant',
				'label' => __( '|-Restaurant', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Winery',
				'label' => __( '|-Winery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'GovernmentOffice',
				'label' => __( 'Government Office', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PostOffice',
				'label' => __( '|-PostOffice', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HealthAndBeautyBusiness',
				'label' => __( 'Health And Beauty Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BeautySalon',
				'label' => __( '|-Beauty Salon', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'DaySpa',
				'label' => __( '|-Day Spa', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HairSalon',
				'label' => __( '|-Hair Salon', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HealthClub',
				'label' => __( '|-Health Club', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'NailSalon',
				'label' => __( '|-Nail Salon', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TattooParlor',
				'label' => __( '|-Tattoo Parlor', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HomeAndConstructionBusiness',
				'label' => __( 'Home And Construction Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Electrician',
				'label' => __( '|-Electrician', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HVACBusiness',
				'label' => __( '|-HVAC Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HousePainter',
				'label' => __( '|-House Painter', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Locksmith',
				'label' => __( '|-Locksmith', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MovingCompany',
				'label' => __( '|-Moving Company', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Plumber',
				'label' => __( '|-Plumber', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'RoofingContractor',
				'label' => __( '|-Roofing Contractor', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'InternetCafe',
				'label' => __( 'Internet Cafe', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MedicalBusiness',
				'label' => __( 'Medical Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'CommunityHealth',
				'label' => __( '|-Community Health', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Dentist',
				'label' => __( '|-Dentist', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Dermatology',
				'label' => __( '|-Dermatology', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'DietNutrition',
				'label' => __( '|-Diet Nutrition', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Emergency',
				'label' => __( '|-Emergency', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Gynecologic',
				'label' => __( '|-Gynecologic', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MedicalClinic',
				'label' => __( '|-Medical Clinic', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Midwifery',
				'label' => __( '|-Midwifery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Nursing',
				'label' => __( '|-Nursing', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Obstetric',
				'label' => __( '|-Obstetric', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Oncologic',
				'label' => __( '|-Oncologic', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Optician',
				'label' => __( '|-Optician', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Optometric',
				'label' => __( '|-Optometric', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Otolaryngologic',
				'label' => __( '|-Otolaryngologic', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Pediatric',
				'label' => __( '|-Pediatric', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Pharmacy',
				'label' => __( '|-Pharmacy', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Physician',
				'label' => __( '|-Physician', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Physiotherapy',
				'label' => __( '|-Physiotherapy', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PlasticSurgery',
				'label' => __( '|-Plastic Surgery', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Podiatric',
				'label' => __( '|-Podiatric', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PrimaryCare',
				'label' => __( '|-Primary Care', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Psychiatric',
				'label' => __( '|-Psychiatric', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PublicHealth',
				'label' => __( '|-Public Health', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'VeterinaryCare',
				'label' => __( '|-Veterinary Care', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'LegalService',
				'label' => __( 'Legal Service', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Attorney',
				'label' => __( '|-Attorney', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Notary',
				'label' => __( '|-Notary', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Library',
				'label' => __( 'Library', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'LodgingBusiness',
				'label' => __( 'Lodging Business', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BedAndBreakfast',
				'label' => __( '|-Bed And Breakfast', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Campground',
				'label' => __( '|-Campground', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Hostel',
				'label' => __( '|-Hostel', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Hotel',
				'label' => __( '|-Hotel', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Motel',
				'label' => __( '|-Motel', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Resort',
				'label' => __( '|-Resort', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ProfessionalService',
				'label' => __( 'Professional Service', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'RadioStation',
				'label' => __( 'Radio Station', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'RealEstateAgent',
				'label' => __( 'Real Estate Agent', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'RecyclingCenter',
				'label' => __( 'Recycling Center', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'SelfStorage',
				'label' => __( 'Real Self Storage', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ShoppingCenter',
				'label' => __( 'Shopping Center', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'SportsActivityLocation',
				'label' => __( 'Sports Activity Location', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BowlingAlley',
				'label' => __( '|-Bowling Alley', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ExerciseGym',
				'label' => __( '|-Exercise Gym', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'GolfCourse',
				'label' => __( '|-Golf Course', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HealthClub',
				'label' => __( '|-Health Club', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PublicSwimmingPool',
				'label' => __( '|-Public Swimming Pool', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'SkiResort',
				'label' => __( '|-Ski Resort', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'SportsClub',
				'label' => __( '|-Sports Club', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'StadiumOrArena',
				'label' => __( '|-Stadium Or Arena', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TennisComplex',
				'label' => __( '|-Tennis Complex', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Store',
				'label' => __( 'Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'AutoPartsStore',
				'label' => __( '|-Auto Parts Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BikeStore',
				'label' => __( '|-Bike Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'BookStore',
				'label' => __( '|-Book Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ClothingStore',
				'label' => __( '|-Clothing Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ComputerStore',
				'label' => __( '|-Computer Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ConvenienceStore',
				'label' => __( '|-Convenience Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'DepartmentStore',
				'label' => __( '|-Department Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ElectronicsStore',
				'label' => __( '|-Electronics Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'Florist',
				'label' => __( '|-Florist', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'FurnitureStore',
				'label' => __( '|-Furniture Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'GardenStore',
				'label' => __( '|-Garden Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'GroceryStore',
				'label' => __( '|-Grocery Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HardwareStore',
				'label' => __( '|-Hardware Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HobbyShop',
				'label' => __( '|-Hobby Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'HomeGoodsStore',
				'label' => __( '|-Home Goods Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'JewelryStore',
				'label' => __( '|-Jewelry Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'LiquorStore',
				'label' => __( '|-Liquor Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MensClothingStore',
				'label' => __( '|-Mens Clothing Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MobilePhoneStore',
				'label' => __( '|-Mobile Phone Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MovieRentalStore',
				'label' => __( '|-Movie Rental Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'MusicStore',
				'label' => __( '|-Music Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'OfficeEquipmentStore',
				'label' => __( '|-Office Equipment Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'OutletStore',
				'label' => __( '|-Outlet Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PawnShop',
				'label' => __( '|-Pawn Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'PetStore',
				'label' => __( '|-Pet Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ShoeStore',
				'label' => __( '|-Shoe Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'SportingGoodsStore',
				'label' => __( '|-Sporting Goods Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TireShop',
				'label' => __( '|-Tire Shop', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'ToyStore',
				'label' => __( '|-Toy Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'WholesaleStore',
				'label' => __( '|-Wholesale Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TelevisionStation',
				'label' => __( '|-Wholesale Store', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TouristInformationCenter',
				'label' => __( 'Tourist Information Center', 'wp-seopress-pro' ),
			),
			array(
				'value' => 'TravelAgency',
				'label' => __( 'Travel Agency', 'wp-seopress-pro' ),
			),
		);

		return apply_filters( 'seopress_schemas_local_business_types', $types );
	}

	/**
	 * @since 4.5.0
	 *
	 * @param string|stdObject $classCallback
	 *
	 * @return void
	 */
	public static function getSettingsSection( $classCallback ) {
		$idSection = 'seopress_setting_section_local_business';
		$page      = 'seopress-settings-admin-local-business';

		$settings = array(
			'section' => array(
				'id'       => $idSection,
				'title'    => '',
				'callback' => array( $classCallback, 'renderSection' ),
				'page'     => $page,
			),
			'fields'  => array(
				array(
					'id'       => 'seopress_local_business_page',
					'title'    => __( 'Where to display the schema?', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldPage' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_type',
					'title'    => __( 'Business type', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldType' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_street_address',
					'title'    => __( 'Street Address', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldStreetAddress' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_address_locality',
					'title'    => __( 'City', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldAddressLocality' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_address_region',
					'title'    => __( 'State', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldAddressRegion' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_postal_code',
					'title'    => __( 'Postal code', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldPostalCode' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_address_country',
					'title'    => __( 'Country', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldAddressCountry' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_lat',
					'title'    => __( 'Latitude', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldLatitude' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_lon',
					'title'    => __( 'Longitude', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldLongitude' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_place_id',
					'title'    => __( 'Place ID', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldPlaceId' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_url',
					'title'    => __( 'URL', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldUrl' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_phone',
					'title'    => __( 'Telephone', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldPhone' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_price_range',
					'title'    => __( 'Price range', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldPriceRange' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_cuisine',
					'title'    => __( 'Cuisine served', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldCuisine' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_menu',
					'title'    => __( 'URL of the menu', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldMenu' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_accepts_reservations',
					'title'    => __( 'Accepts Reservations', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldAcceptsReservations' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_opening_hours',
					'title'    => __( 'Opening hours', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldOpeningHours' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_opening_hours_display_format',
					'title'    => __( 'Display Format', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldOpeningHoursDisplayFormat' ),
					'page'     => $page,
					'section'  => $idSection,
				),
				array(
					'id'       => 'seopress_local_business_opening_hours_separator',
					'title'    => __( 'Time Separator', 'wp-seopress-pro' ),
					'callback' => array( $classCallback, 'renderFieldOpeningHoursSeparator' ),
					'page'     => $page,
					'section'  => $idSection,
				),

			),
		);

		return $settings;
	}
}
