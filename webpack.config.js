const path = require('path');

// Two builds: the admin app (a script), and the listings' client (a script
// module, for the Interactivity API). wp-scripts builds modules with this flag.
process.env.WP_EXPERIMENTAL_MODULES = 'true';

const [scriptConfig, moduleConfig] = require('@wordpress/scripts/config/webpack.config');

module.exports = [
	{
		...scriptConfig,
		entry: {
			// Not admin.js: wp i18n make-json turns any name ending in "min.js" into a wrong file name
			app: path.resolve(__dirname, 'resources/admin/index.js'),
		},
		output: {
			...scriptConfig.output,
			path: path.resolve(__dirname, 'build'),
		},
	},
	{
		...moduleConfig,
		entry: {
			'listings/view': path.resolve(__dirname, 'resources/listings/view.js'),
		},
		output: {
			...moduleConfig.output,
			path: path.resolve(__dirname, 'build'),
		},
	},
];
