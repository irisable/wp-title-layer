const assert = require( 'node:assert/strict' );
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );
const test = require( 'node:test' );

const root = path.resolve( __dirname, '..' );
const versionGate = path.join( root, 'bin', 'release-version.sh' );
const buildRelease = path.join( root, 'bin', 'build-release.sh' );

function run( command, args, options = {} ) {
	return spawnSync( command, args, {
		cwd: options.cwd || root,
		encoding: 'utf8',
		env: { ...process.env, ...options.env },
	} );
}

function writeFixture( directory, versions = {} ) {
	const values = {
		header: '1.0.0-rc.1',
		constant: '1.0.0-rc.1',
		package: '1.0.0-rc.1',
		lockTop: '1.0.0-rc.1',
		lockRoot: '1.0.0-rc.1',
		stable: '1.0.0-rc.1',
		po: '1.0.0-rc.1',
		pot: '1.0.0-rc.1',
		...versions,
	};
	fs.writeFileSync(
		path.join( directory, 'wp-title-layer.php' ),
		`<?php\n/**\n * Plugin Name: WP Title Layer\n * Version: ${ values.header }\n */\ndefine( 'WPTL_VERSION', '${ values.constant }' );\n`
	);
	fs.writeFileSync(
		path.join( directory, 'package.json' ),
		`${ JSON.stringify( { name: 'wp-title-layer', version: values.package }, null, 2 ) }\n`
	);
	fs.writeFileSync(
		path.join( directory, 'package-lock.json' ),
		`${ JSON.stringify( {
			name: 'wp-title-layer',
			version: values.lockTop,
			lockfileVersion: 3,
			packages: { '': { name: 'wp-title-layer', version: values.lockRoot } },
		}, null, 2 ) }\n`
	);
	fs.writeFileSync(
		path.join( directory, 'readme.txt' ),
		`=== WP Title Layer ===\nStable tag: ${ values.stable }\n`
	);
	fs.mkdirSync( path.join( directory, 'languages' ), { recursive: true } );
	fs.writeFileSync(
		path.join( directory, 'languages', 'wp-title-layer-zh_CN.po' ),
		`msgid ""\nmsgstr ""\n"Project-Id-Version: WP Title Layer ${ values.po }\\n"\n`
	);
	fs.writeFileSync(
		path.join( directory, 'languages', 'wp-title-layer.pot' ),
		`msgid ""\nmsgstr ""\n"Project-Id-Version: WP Title Layer ${ values.pot }\\n"\n`
	);
}

function writePackageFixture( directory, versions = {} ) {
	const values = {
		header: '1.0.0-rc.1',
		constant: '1.0.0-rc.1',
		stable: '1.0.0-rc.1',
		...versions,
	};
	fs.writeFileSync(
		path.join( directory, 'wp-title-layer.php' ),
		`<?php\n/**\n * Plugin Name: WP Title Layer\n * Version: ${ values.header }\n */\ndefine( 'WPTL_VERSION', '${ values.constant }' );\n`
	);
	fs.writeFileSync(
		path.join( directory, 'readme.txt' ),
		`=== WP Title Layer ===\nStable tag: ${ values.stable }\n`
	);
}

function installPackageGateScripts( directory ) {
	const binDirectory = path.join( directory, 'bin' );
	fs.mkdirSync( binDirectory );
	fs.copyFileSync( versionGate, path.join( binDirectory, 'release-version.sh' ) );
	fs.copyFileSync(
		path.join( root, 'bin', 'test-release-package.sh' ),
		path.join( binDirectory, 'test-release-package.sh' )
	);
	fs.chmodSync( path.join( binDirectory, 'release-version.sh' ), 0o755 );
	fs.chmodSync( path.join( binDirectory, 'test-release-package.sh' ), 0o755 );
	return binDirectory;
}

test( 'release version gate accepts one matching SemVer contract', () => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-release-version-' ) );
	try {
		writeFixture( directory );
		const result = run( versionGate, [ 'check-directory', '1.0.0-rc.1', directory ] );
		assert.equal( result.status, 0, result.stderr );
		assert.match( result.stdout, /Release version contract verified: 1\.0\.0-rc\.1/ );
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );

test( 'release version gate identifies every drifting public version field', async ( t ) => {
	for ( const field of [ 'header', 'constant', 'package', 'lockTop', 'lockRoot', 'stable', 'po', 'pot' ] ) {
		await t.test( field, () => {
			const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-release-version-' ) );
			try {
				writeFixture( directory, { [ field ]: '0.11.0' } );
				const result = run( versionGate, [ 'check-directory', '1.0.0-rc.1', directory ] );
				assert.notEqual( result.status, 0 );
				assert.match( result.stderr, /expected '1\.0\.0-rc\.1'/ );
			} finally {
				fs.rmSync( directory, { recursive: true, force: true } );
			}
		} );
	}
} );

test( 'release version gate rejects unsafe or ambiguous archive versions', () => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-release-version-' ) );
	try {
		writeFixture( directory );
		for ( const invalidVersion of [ '1.0', '1.0.0 rc.1', '../1.0.0', 'v1.0.0-rc.1' ] ) {
			const result = run( versionGate, [ 'check-directory', invalidVersion, directory ] );
			assert.notEqual( result.status, 0, invalidVersion );
			assert.match( result.stderr, /not a valid release version/ );
		}
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );

test( 'release builder validates the committed ref before replacing an archive', () => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-release-build-' ) );
	const binDirectory = path.join( directory, 'bin' );
	try {
		fs.mkdirSync( binDirectory );
		fs.copyFileSync( versionGate, path.join( binDirectory, 'release-version.sh' ) );
		fs.copyFileSync( buildRelease, path.join( binDirectory, 'build-release.sh' ) );
		fs.chmodSync( path.join( binDirectory, 'release-version.sh' ), 0o755 );
		fs.chmodSync( path.join( binDirectory, 'build-release.sh' ), 0o755 );
		writeFixture( directory );
		fs.writeFileSync( path.join( directory, '.gitattributes' ), '/bin export-ignore\n/package.json export-ignore\n' );

		assert.equal( run( 'git', [ 'init', '-q' ], { cwd: directory } ).status, 0 );
		assert.equal( run( 'git', [ 'add', '.' ], { cwd: directory } ).status, 0 );
		assert.equal(
			run( 'git', [ '-c', 'user.name=WP Title Layer Tests', '-c', 'user.email=tests@example.invalid', 'commit', '-qm', 'matching release' ], { cwd: directory } ).status,
			0
		);

		const success = run( path.join( binDirectory, 'build-release.sh' ), [ '1.0.0-rc.1' ], { cwd: directory } );
		assert.equal( success.status, 0, success.stderr );
		const archive = path.join( directory, 'wp-title-layer-1.0.0-rc.1.zip' );
		const originalHash = crypto.createHash( 'sha256' ).update( fs.readFileSync( archive ) ).digest( 'hex' );

		writeFixture( directory, { constant: '0.11.0' } );
		const committedRef = run( path.join( binDirectory, 'build-release.sh' ), [], { cwd: directory } );
		assert.equal( committedRef.status, 0, committedRef.stderr );
		assert.match( committedRef.stdout, /Release version contract verified: 1\.0\.0-rc\.1 at HEAD/ );
		assert.equal( run( 'git', [ 'add', 'wp-title-layer.php' ], { cwd: directory } ).status, 0 );
		assert.equal(
			run( 'git', [ '-c', 'user.name=WP Title Layer Tests', '-c', 'user.email=tests@example.invalid', 'commit', '-qm', 'drifted release' ], { cwd: directory } ).status,
			0
		);

		const failure = run( path.join( binDirectory, 'build-release.sh' ), [ '1.0.0-rc.1' ], { cwd: directory } );
		assert.notEqual( failure.status, 0 );
		assert.match( failure.stderr, /WPTL_VERSION is '0\.11\.0'/ );
		const preservedHash = crypto.createHash( 'sha256' ).update( fs.readFileSync( archive ) ).digest( 'hex' );
		assert.equal( preservedHash, originalHash );
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );

test( 'packaged artifacts are checked against the version in their ZIP filename', ( t ) => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-package-version-' ) );
	const payloadDirectory = path.join( directory, 'payload' );
	const pluginDirectory = path.join( payloadDirectory, 'wp-title-layer' );
	try {
		const binDirectory = installPackageGateScripts( directory );
		fs.mkdirSync( pluginDirectory, { recursive: true } );
		writeFixture( directory );
		writePackageFixture( pluginDirectory, { header: '0.11.0', constant: '0.11.0', stable: '0.11.0' } );

		const archive = path.join( directory, 'wp-title-layer-1.0.0-rc.1.zip' );
		const zip = run( 'zip', [ '-qr', archive, 'wp-title-layer' ], { cwd: payloadDirectory } );
		if ( zip.error && zip.error.code === 'ENOENT' ) {
			t.skip( 'zip is unavailable in this environment' );
			return;
		}
		assert.equal( zip.status, 0, zip.stderr );

		const result = run( path.join( binDirectory, 'test-release-package.sh' ), [ archive ], { cwd: directory } );
		assert.notEqual( result.status, 0 );
		assert.match( result.stderr, /plugin Version header is '0\.11\.0', expected '1\.0\.0-rc\.1'/ );
		assert.doesNotMatch( result.stderr, /WordPress Playground is missing/ );
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );

test( 'package gate rejects an entry outside the unique plugin root', ( t ) => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-package-root-' ) );
	const payloadDirectory = path.join( directory, 'payload' );
	const pluginDirectory = path.join( payloadDirectory, 'wp-title-layer' );
	try {
		const binDirectory = installPackageGateScripts( directory );
		fs.mkdirSync( pluginDirectory, { recursive: true } );
		writeFixture( directory );
		writePackageFixture( pluginDirectory );
		fs.writeFileSync( path.join( payloadDirectory, 'unexpected.txt' ), 'not part of the plugin\n' );

		const archive = path.join( directory, 'wp-title-layer-1.0.0-rc.1.zip' );
		const zip = run( 'zip', [ '-qr', archive, 'wp-title-layer', 'unexpected.txt' ], { cwd: payloadDirectory } );
		if ( zip.error && zip.error.code === 'ENOENT' ) {
			t.skip( 'zip is unavailable in this environment' );
			return;
		}
		assert.equal( zip.status, 0, zip.stderr );

		const result = run( path.join( binDirectory, 'test-release-package.sh' ), [ archive ], { cwd: directory } );
		assert.notEqual( result.status, 0 );
		assert.match( result.stderr, /outside the unique wp-title-layer\/ root: unexpected\.txt/ );
		assert.doesNotMatch( result.stderr, /WordPress Playground is missing/ );
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );

test( 'package gate rejects a release-excluded development directory', ( t ) => {
	const directory = fs.mkdtempSync( path.join( os.tmpdir(), 'wptl-package-excluded-' ) );
	const payloadDirectory = path.join( directory, 'payload' );
	const pluginDirectory = path.join( payloadDirectory, 'wp-title-layer' );
	try {
		const binDirectory = installPackageGateScripts( directory );
		fs.mkdirSync( path.join( pluginDirectory, 'docs' ), { recursive: true } );
		writeFixture( directory );
		writePackageFixture( pluginDirectory );
		fs.writeFileSync( path.join( pluginDirectory, 'docs', 'private.md' ), 'must not ship\n' );

		const archive = path.join( directory, 'wp-title-layer-1.0.0-rc.1.zip' );
		const zip = run( 'zip', [ '-qr', archive, 'wp-title-layer' ], { cwd: payloadDirectory } );
		if ( zip.error && zip.error.code === 'ENOENT' ) {
			t.skip( 'zip is unavailable in this environment' );
			return;
		}
		assert.equal( zip.status, 0, zip.stderr );

		const result = run( path.join( binDirectory, 'test-release-package.sh' ), [ archive ], { cwd: directory } );
		assert.notEqual( result.status, 0 );
		assert.match( result.stderr, /release-excluded path is present: docs\// );
		assert.doesNotMatch( result.stderr, /WordPress Playground is missing/ );
	} finally {
		fs.rmSync( directory, { recursive: true, force: true } );
	}
} );
