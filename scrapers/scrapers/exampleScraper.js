const puppeteer = require('puppeteer');
// fs is not strictly needed if using file:// URLs directly with Puppeteer's goto.
// const fs = require('fs');

async function scrapeExample(filePath) {
  let browser;
  try {
    // Launch the browser. Add { headless: "new" } for new headless mode,
    // or remove entirely for default (which is new headless as of Puppeteer v22).
    // Older versions might need { headless: true }.
    // For CI environments, you might need { args: ['--no-sandbox', '--disable-setuid-sandbox'] }
    browser = await puppeteer.launch({ headless: "new", args: ['--no-sandbox', '--disable-setuid-sandbox'] });
    const page = await browser.newPage();

    // Ensure the filePath is an absolute path before creating the file URL
    // The test file will handle path.resolve, so filePath should be absolute here.
    await page.goto(`file://${filePath}`, { waitUntil: 'load' }); // Changed from networkidle0 to load

    const heading = await page.$eval('h1', element => element.textContent);

    // Example of extracting more data if needed
    // const paragraph = await page.$eval('p', element => element.textContent);

    return { heading };
  } catch (error) {
    console.error(`Scraping failed for ${filePath}:`, error);
    // Include filePath in the error for better debugging in tests
    throw new Error(`Scraping ${filePath} failed: ${error.message}`);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

module.exports = { scrapeExample };
