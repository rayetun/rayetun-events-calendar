/**
 * Render a local HTML or SVG file to PNG with the Chrome that is already on
 * this machine. No download, no extra dependency beyond puppeteer-core, which
 * @wordpress/scripts already brings in.
 *
 *   node tools/render.js <input.html|input.svg> <output.png> <width> <height> [scale] [transparent]
 *
 * Animations are parked by emulating prefers-reduced-motion, so a still frame
 * is always the resting state and never a random point in a loop. Pass
 * "transparent" as the last argument to keep the page background clear, which
 * is what the icon PNGs need.
 *
 * WordPress.org wants banners as PNG or JPG, so the banners are designed as
 * SVG and rendered here. The icon stays SVG, which WordPress.org accepts and
 * which keeps its animation.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const puppeteer = require( 'puppeteer-core' );

const CHROME = [
	'C:/Program Files/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
	'/usr/bin/google-chrome',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find( ( p ) => fs.existsSync( p ) );

async function main() {
	const [ input, output, w, h, scale, alpha ] = process.argv.slice( 2 );
	if ( ! input || ! output ) {
		console.error( 'usage: node tools/render.js <input> <output.png> <width> <height> [scale]' );
		process.exit( 1 );
	}
	if ( ! CHROME ) {
		console.error( 'No Chrome or Edge found. Install one, or add its path to tools/render.js.' );
		process.exit( 1 );
	}

	const width = parseInt( w, 10 ) || 1544;
	const height = parseInt( h, 10 ) || 500;
	const factor = parseFloat( scale ) || 1;
	const transparent = String( alpha || '' ).toLowerCase() === 'transparent';

	const browser = await puppeteer.launch( {
		executablePath: CHROME,
		args: [ '--allow-file-access-from-files', '--force-color-profile=srgb', '--font-render-hinting=none' ],
	} );
	const page = await browser.newPage();
	await page.emulateMediaFeatures( [
		{ name: 'prefers-reduced-motion', value: 'reduce' },
	] );
	await page.setViewport( { width, height, deviceScaleFactor: factor } );
	await page.goto( 'file:///' + path.resolve( input ).replace( /\\/g, '/' ), { waitUntil: 'networkidle0' } );

	// Let webfonts settle and any entry animation reach its resting state.
	await page.evaluate( () => document.fonts && document.fonts.ready );
	await new Promise( ( r ) => setTimeout( r, 400 ) );

	fs.mkdirSync( path.dirname( path.resolve( output ) ), { recursive: true } );
	await page.screenshot( { path: path.resolve( output ), type: 'png', omitBackground: transparent } );
	await browser.close();

	const size = fs.statSync( path.resolve( output ) ).size;
	console.log(
		`${ output }  ${ width }x${ height }@${ factor }x  ${ Math.round( size / 1024 ) } KB` +
			( transparent ? '  transparent' : '' )
	);
}

main().catch( ( e ) => {
	console.error( e.message );
	process.exit( 1 );
} );
