const fs = require('fs');
const path = require('path');

const historyDir = 'C:\\Users\\Admin\\AppData\\Roaming\\Kiro\\User\\History';
const targetPrefix = 'c:/wamp64/www/ERP-horizontal-ai/';

function restoreFiles() {
    let restoredCount = 0;
    const dirs = fs.readdirSync(historyDir);
    const latestFiles = new Map();

    for (const dir of dirs) {
        const entriesPath = path.join(historyDir, dir, 'entries.json');
        if (!fs.existsSync(entriesPath)) continue;

        try {
            const data = JSON.parse(fs.readFileSync(entriesPath, 'utf8'));
            if (!data.resource) continue;

            let resourcePath = data.resource.replace(/^file:\/\/\//i, '');
            resourcePath = decodeURIComponent(resourcePath).replace(/\\/g, '/');

            // Find where "erp-horizontal" is in the path
            const lowerPath = resourcePath.toLowerCase();
            const matchIndex = lowerPath.lastIndexOf('erp-horizontal');
            if (matchIndex === -1) continue;

            // Extract the relative path AFTER the ERP-horizontal folder
            // It could be ERP-horizontal/, ERP-horizontal-ai/, ERP-horizontal/ERP-horizontal/
            // We want everything after the last 'erp-horizontal*' directory
            const parts = resourcePath.split('/');
            let relativePathParts = [];
            let found = false;
            for (let i = parts.length - 1; i >= 0; i--) {
                if (parts[i].toLowerCase().startsWith('erp-horizontal')) {
                    relativePathParts = parts.slice(i + 1);
                    found = true;
                    break;
                }
            }
            
            if (!found || relativePathParts.length === 0) continue;
            
            const relativePath = relativePathParts.join('/');
            
            const entries = data.entries;
            if (!entries || entries.length === 0) continue;

            const lastEntry = entries[entries.length - 1];
            const timestamp = lastEntry.timestamp || 0;
            const backupFilePath = path.join(historyDir, dir, lastEntry.id);

            if (fs.existsSync(backupFilePath)) {
                if (!latestFiles.has(relativePath) || latestFiles.get(relativePath).timestamp < timestamp) {
                    latestFiles.set(relativePath, {
                        backupFilePath: backupFilePath,
                        timestamp: timestamp
                    });
                }
            }
        } catch (err) {
            // ignore
        }
    }

    for (const [relativePath, info] of latestFiles.entries()) {
        const targetPath = targetPrefix + relativePath;
        const targetDir = path.dirname(targetPath);
        
        if (!fs.existsSync(targetDir)) {
            fs.mkdirSync(targetDir, { recursive: true });
        }

        // Only overwrite if it doesn't exist, OR if we want to force everything
        // Since we want to recover the whole project, we will overwrite.
        fs.copyFileSync(info.backupFilePath, targetPath);
        console.log(`Restored: ${relativePath}`);
        restoredCount++;
    }
    
    console.log(`\nTotal files restored: ${restoredCount}`);
}

restoreFiles();
