/**
 * The build lives outside the plugin folder on purpose.
 *
 * Everything inside rayetun-events-calendar/ is what people download: no
 * sources, no node_modules, no tooling. This config reads from ./src here and
 * writes the finished files into the plugin's build/ folder.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const plugin = path.resolve( __dirname, 'rayetun-events-calendar' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
		'calendar-block': path.resolve( __dirname, 'src/blocks/calendar.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( plugin, 'build' ),
	},
};
