#!/usr/bin/env node

const chokidar = require('chokidar');
const process = require('process');
const child_process = require('child_process');

let runWithDocker = false;
try {
    let filteredOutput = child_process.execSync('docker compose ps').toString().split(/\r?\n/).filter(line => line.match(/\bphp-fpm\b/));
    runWithDocker = filteredOutput.length > 0;
} catch (e) {
    console.log(e.message);
}

let entryPoint;
let arguments;
let events = [];
let activeChild = null;
let failures = 0;

if (runWithDocker) {
    console.log('Docker container running. Running with docker...');
    entryPoint = 'docker';
    arguments = ['compose', 'exec', 'php', 'php', 'bin/console'];
} else {
    console.log('Docker container not running. Running natively...');
    entryPoint = 'php';
    arguments = ['bin/console'];
}

// setup file watcher
chokidar.watch([__dirname + '/../src', __dirname + '/../assets/vue/vue_routes.yaml', ]).on('all', (event, path) => {
    // console.log(event, path);
    events.push({event, path});
});

// setup rebuild timer
setInterval(() => {
    if (events.length === 0) {
        return;
    }

    console.log('Rebuilding...');
    if (activeChild) {
        console.log('Build is already running. Skipping...');
        return;
    }

    activeChild = child_process.spawn(entryPoint, [...arguments, 'ovrflo:api-platform:typescript:generate', '--ansi'], {
        shell: true,
        env: {
            ...process.env,
            'TERM': 'xterm-256color',
        }
    });
    activeChild.stdout.pipe(process.stdout);
    activeChild.stderr.pipe(process.stderr);

    activeChild.once('exit', (code) => {
        activeChild = null;

        if (code > 0) {
            console.log('Child exited with code ' + code);
            failures++;
            if (failures > 10) {
                console.log('Too many consecutive failures. Exiting...');
                process.exit(1);
            }
        } else {
            console.log();
            failures = 0;
        }
    });

    events = [];
}, 100);
