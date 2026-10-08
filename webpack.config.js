const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		// Not admin.js: wp i18n make-json turns any name ending in "min.js" into a wrong file name
		app: path.resolve(__dirname, 'resources/admin/index.js'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
	},
};
