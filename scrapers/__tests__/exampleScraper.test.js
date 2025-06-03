const path = require('path');
const { scrapeExample } = require('../scrapers/exampleScraper'); // Adjust path as necessary

describe('Example Scraper', () => {
  it('should extract heading from the example HTML file', async () => {
    // Construct an absolute path to the fixture file
    const filePath = path.resolve(__dirname, '../fixtures/example.html');

    // Log the path for debugging in case of errors
    // console.log(`Attempting to scrape file at: ${filePath}`);

    try {
      const result = await scrapeExample(filePath);
      expect(result).toEqual({ heading: 'Hello Scraper' });
    } catch (error) {
      // Make sure test fails with the scraper's error
      throw error;
    }
  });
});
