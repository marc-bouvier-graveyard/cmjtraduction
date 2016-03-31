<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'cmjtradu_cmj');

/** MySQL database username */
define('DB_USER', 'cmjtradu_cmj');

/** MySQL database password */
define('DB_PASSWORD', '7SbPdEtL68');

/** MySQL hostname */
define('DB_HOST', 'MySQL-01');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         '2tzl3kusruivupm6f9hz319asfveyrybww1hwdkfpaghzel8vyamgxr3nfmgxlp4');
define('SECURE_AUTH_KEY',  'nuoj976fna8mginppf9f9zagnfain7mxlouidwjxesof58dvbyuhsgfkkp77mzhc');
define('LOGGED_IN_KEY',    'mcqs2tf50d4dnl2ddeho2wckcfyvpno1ix0j4vwljjjkknh1kisnmptrzm6bzjt6');
define('NONCE_KEY',        '7cxyzdjhr8ebg4iycqq9whek25jzs5eutzpjlxis0y880atsgm8bgoj6zsuaw8io');
define('AUTH_SALT',        'hyzt37iinibpuozru7b1d05akplcmia8gkhcb1ew8pcjpl7t3u1ctvuxlosmkqnq');
define('SECURE_AUTH_SALT', 'avgwivmhtxiv5zvfgeiljowlnuuijypbguthlppsyz5dfkcq43omklllvlsivnct');
define('LOGGED_IN_SALT',   'gi1abr4ogh5tz5ure7heanhbqxlltelnsl2ex3efwmkduqwcy95beduprsy4s0fu');
define('NONCE_SALT',       'idsa7inymllghdbz1hajfsqdkopoyqwburpnlkn2m15ahaonouuikjukpsqt35r3');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'cmj_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);

/* Multisite */
define( 'WP_ALLOW_MULTISITE', true );
define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);
define('DOMAIN_CURRENT_SITE', 'www.cmj-traduction.fr');
define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
