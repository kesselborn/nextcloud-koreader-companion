<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Http\XMLResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;

class OpdsController extends Controller {

    private $bookService;
    private $userSession;
    private $throttler;
    private $urlGenerator;

    /** Set when core reported the caller is already being delayed. */
    private bool $throttled = false;

    /**
     * Whether the request actually carried credentials.
     *
     * A request with no Authorization header is not a failed login -- it is the
     * first half of HTTP Basic authentication. Every OPDS reader sends one, gets
     * the 401 with WWW-Authenticate, and repeats with credentials.
     */
    private bool $credentialsOffered = false;

    public function __construct(IRequest $request, $appName, BookService $bookService, IUserSession $userSession, IThrottler $throttler, IURLGenerator $urlGenerator) {
        parent::__construct($appName, $request);
        $this->bookService = $bookService;
        $this->userSession = $userSession;
        $this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Authenticate user using HTTP Basic Auth
     * Supports both regular passwords and app password tokens
     * @return bool True if authenticated, false otherwise
     */
    private function authenticateBasicAuth(): bool {
        $authHeader = $this->request->getHeader('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        // Strict: a malformed header should fail, not decode to garbage that then
        // fails a login attempt and burns a throttle slot.
        $credentials = base64_decode(substr($authHeader, 6), true);
        if ($credentials === false) {
            return false;
        }

        $parts = explode(':', $credentials, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$username, $password] = $parts;
        $this->credentialsOffered = true;

        try {
            // Use logClientIn() which handles app passwords, LDAP, 2FA, and throttling
            return $this->userSession->logClientIn(
                $username,
                $password,
                $this->request,
                $this->throttler
            );
        } catch (\OCP\Authentication\Exceptions\PasswordLoginForbiddenException $ex) {
            return false;
        } catch (\OCP\Security\Bruteforce\MaxDelayReached $ex) {
            // Distinguished from a wrong password on purpose. Collapsing it into a
            // 401 told a throttled client to keep trying, and meant the app never
            // surfaced that the platform had started delaying it.
            $this->throttled = true;
            return false;
        }
    }

    /**
     * The single auth gate for every OPDS endpoint.
     *
     * @return DataResponse|null The response to send, or null when authenticated.
     */
    private function requireBasicAuth(): ?DataResponse {
        if ($this->authenticateBasicAuth()) {
            return null;
        }

        if ($this->throttled) {
            return new DataResponse(['error' => 'Too many requests'], Http::STATUS_TOO_MANY_REQUESTS);
        }

        $response = new DataResponse(['error' => 'Unauthorized'], 401);
        $response->addHeader('WWW-Authenticate', 'Basic realm="Nextcloud OPDS"');

        // Only a rejected *credential* counts against the throttler. Counting the
        // credential-less first request as well meant ordinary browsing tripped the
        // brute-force protection: every reader opens with one, so a handful of page
        // loads was enough to earn a 429.
        if ($this->credentialsOffered) {
            $response->throttle(['action' => 'koreader_opds']);
        }

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function index() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        $sort = $this->request->getParam('sort', 'title');

        $totalCount = $this->bookService->getTotalBookCount(true);

        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }

        $books = $this->bookService->getBooks($page, $perPage, $sort, true);
        $xml = $this->generateRootCatalogXml($books, $page, $perPage, $totalCount, $sort);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function opensearch() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $searchUrl = $this->getSearchUrl();
        $baseUrl = $this->getBaseUrl();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
    <ShortName>Nextcloud eBooks</ShortName>
    <Description>Search Nextcloud eBooks Library</Description>
    <Url type="application/atom+xml;profile=opds-catalog"
         template="' . $searchUrl . '?q={searchTerms}"/>
    <Image height="64" width="64" type="image/png">' . $baseUrl . '/favicon.png</Image>
    <Query role="example" searchTerms="science fiction"/>
</OpenSearchDescription>';

        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/opensearchdescription+xml');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function search() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $query = $this->request->getParam('q', '');
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));

        $totalCount = $this->bookService->getSearchResultCount($query, true);

        $validationResponse = $this->validatePagination($page, $perPage, $totalCount, $query);
        if ($validationResponse !== null) {
            return $validationResponse;
        }

        $books = $this->bookService->searchBooks($query, $page, $perPage, true);
        $title = empty($query) ? 'All Books' : 'Search Results: ' . $query;
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title, $query);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function download($id, $format) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $book = $this->bookService->getBookById($id);
        
        if (!$book) {
            return new DataResponse(['error' => 'Book not found'], 404);
        }

        return $this->bookService->downloadBook($book, $format);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function thumbnail($id) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $book = $this->bookService->getBookById($id);
        
        if (!$book) {
            return new DataResponse(['error' => 'Book not found'], 404);
        }

        return $this->bookService->getThumbnail($book);
    }

    private function generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title = 'Nextcloud eBooks Library', $searchQuery = '') {
        $baseUrl = $this->getBaseUrl();
        $searchUrl = $this->getSearchUrl();
        $opensearchUrl = $this->getOpenSearchUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $totalPages = ceil($totalCount / $perPage);
        
        $currentUrl = $baseUrl;
        $urlParams = [];
        if ($page > 1) {
            $urlParams['page'] = $page;
        }
        if (!empty($searchQuery)) {
            $currentUrl = $searchUrl;
            $urlParams['q'] = $searchQuery;
        }
        if (!empty($urlParams)) {
            $currentUrl .= '?' . http_build_query($urlParams);
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:dc="http://purl.org/dc/terms/"
      xmlns:opds="http://opds-spec.org/2010/catalog">
  
  <id>urn:uuid:' . md5($baseUrl . $searchQuery . $page) . '</id>
  <title>' . htmlspecialchars($title) . '</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($currentUrl) . '"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" 
        href="' . $baseUrl . '"/>
  <link rel="search" type="application/opensearchdescription+xml" 
        href="' . $opensearchUrl . '"/>
';

        if ($totalPages > 1) {
            if ($page > 1) {
                $firstUrl = empty($searchQuery) ? $baseUrl : $searchUrl . '?q=' . urlencode($searchQuery);
                $xml .= '  <link rel="first" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($firstUrl) . '"/>
';
                
                $prevPage = $page - 1;
                $prevParams = [];
                if ($prevPage > 1) {
                    $prevParams['page'] = $prevPage;
                }
                if (!empty($searchQuery)) {
                    $prevParams['q'] = $searchQuery;
                }
                $prevUrl = (empty($searchQuery) ? $baseUrl : $searchUrl);
                if (!empty($prevParams)) {
                    $prevUrl .= '?' . http_build_query($prevParams);
                }
                $xml .= '  <link rel="previous" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($prevUrl) . '"/>
';
            }
            
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $nextParams = ['page' => $nextPage];
                if (!empty($searchQuery)) {
                    $nextParams['q'] = $searchQuery;
                }
                $nextUrl = (empty($searchQuery) ? $baseUrl : $searchUrl) . '?' . http_build_query($nextParams);
                $xml .= '  <link rel="next" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($nextUrl) . '"/>
';
                
                $lastParams = ['page' => $totalPages];
                if (!empty($searchQuery)) {
                    $lastParams['q'] = $searchQuery;
                }
                $lastUrl = (empty($searchQuery) ? $baseUrl : $searchUrl) . '?' . http_build_query($lastParams);
                $xml .= '  <link rel="last" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($lastUrl) . '"/>
';
            }
        }

        $xml .= '  
';

        foreach ($books as $book) {
            if ($book !== null) { // Filter out null entries from database conversion failures
                $xml .= $this->generateBookEntry($book, $baseUrl);
            }
        }

        $xml .= '</feed>';
        
        return $xml;
    }

    /**
     * Legacy method maintained for backward compatibility
     */
    private function generateOpdsXml($books, $title = 'Nextcloud eBooks Library') {
        return $this->generatePaginatedOpdsXml($books, 1, count($books), count($books), $title);
    }

    private function generateBookEntry($book, $baseUrl) {
        $bookId = $book['id'];
        $title = htmlspecialchars($book['title'] ?? 'Unknown Title');
        $author = htmlspecialchars($book['author'] ?? 'Unknown Author');
        $description = htmlspecialchars($book['description'] ?? '');
        $updated = gmdate('Y-m-d\TH:i:s\Z', $book['modified_time'] ?? time());
        $format = strtolower($book['format'] ?? 'epub');
        
        $mimeType = $this->getMimeType($format);
        
        return '  <entry>
    <id>urn:uuid:book-' . $bookId . '</id>
    <title>' . $title . '</title>
    <author><name>' . $author . '</name></author>
    <updated>' . $updated . '</updated>
    <dc:language>en</dc:language>
    <content type="text">' . $description . '</content>
    
    <link rel="http://opds-spec.org/acquisition" 
          type="' . $mimeType . '" 
          href="' . $baseUrl . '/books/' . $bookId . '/download/' . $format . '"/>'
    . $this->thumbnailLink($baseUrl, $bookId, $format) . '
  </entry>
';
    }

    /**
     * Thumbnail link, but only for formats that can actually produce one.
     *
     * Previously advertised unconditionally, so OPDS readers requested covers
     * for PDF and got an error back -- which clients render as a broken
     * image rather than as "no cover". PDF is excluded because Nextcloud 34.0.2
     * disabled all ImageMagick-backed preview providers for security
     * (nextcloud/server#62802), not because of anything in this app.
     */
    private function thumbnailLink(string $baseUrl, $bookId, string $format): string {
        $withCovers = ['epub', 'cbz', 'cbr'];
        if (!in_array(strtolower($format), $withCovers, true)) {
            return '';
        }

        return '
    <link rel="http://opds-spec.org/image/thumbnail"
          type="image/jpeg"
          href="' . $baseUrl . '/books/' . $bookId . '/thumb"/>';
    }

    private function getMimeType($format) {
        $mimeTypes = [
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbr' => 'application/comicbook+rar',
            'cbz' => 'application/comicbook+zip',
            'txt' => 'text/plain'
        ];
        
        return $mimeTypes[$format] ?? 'application/octet-stream';
    }

    private function getBaseUrl() {
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute($this->appName . '.opds.index'));
    }

    private function getOpenSearchUrl() {
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute($this->appName . '.opds.opensearch'));
    }

    private function getSearchUrl() {
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute($this->appName . '.opds.search'));
    }

    /**
     * Validate pagination parameters and redirect if necessary
     */
    private function validatePagination($page, $perPage, $totalCount, $searchQuery = '') {
        $totalPages = ceil($totalCount / $perPage);
        
        if ($totalCount === 0) {
            return null;
        }
        
        if ($page > $totalPages && $totalPages > 0) {
            $baseRoute = empty($searchQuery) ? 'koreader_companion.opds.index' : 'koreader_companion.opds.search';
            
            $params = [];
            if ($totalPages > 1) {
                $params['page'] = $totalPages;
            }
            if (!empty($searchQuery)) {
                $params['q'] = $searchQuery;
            }
            
            $redirectUrl = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute($baseRoute, $params)
            );
            
            return new RedirectResponse($redirectUrl);
        }
        
        return null; // Valid pagination
    }

    /**
     * Generate root catalog XML with facet links
     */
    private function generateRootCatalogXml($books, $page, $perPage, $totalCount, $sort) {
        $baseUrl = $this->getBaseUrl();
        $searchUrl = $this->getSearchUrl();
        $opensearchUrl = $this->getOpenSearchUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $totalPages = ceil($totalCount / $perPage);
        
        $currentUrl = $baseUrl;
        $urlParams = [];
        if ($page > 1) {
            $urlParams['page'] = $page;
        }
        if ($sort !== 'title') {
            $urlParams['sort'] = $sort;
        }
        if (!empty($urlParams)) {
            $currentUrl .= '?' . http_build_query($urlParams);
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '</id>
  <title>Nextcloud eBooks Library</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <!-- Navigation Links -->
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($currentUrl) . '"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="search" type="application/opensearchdescription+xml" href="' . htmlspecialchars($opensearchUrl) . '"/>
  
  <!-- Facet Links -->
  <link rel="http://opds-spec.org/facet" 
        title="By Author" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '/authors"/>
  <link rel="http://opds-spec.org/facet" 
        title="By Series" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '/series"/>
  <link rel="http://opds-spec.org/facet" 
        title="By Genre" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '/genres"/>
  <link rel="http://opds-spec.org/facet" 
        title="By Format" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '/formats"/>
  <link rel="http://opds-spec.org/facet" 
        title="By Language" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '/languages"/>
  
  <!-- Sort Links -->
  <link rel="http://opds-spec.org/sort/popular" 
        title="Most Recent" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '?sort=recent"/>
  <link rel="http://opds-spec.org/sort/new" 
        title="By Author" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '?sort=author"/>
  <link rel="http://opds-spec.org/sort/title" 
        title="Alphabetical" 
        type="application/atom+xml;profile=opds-catalog"
        href="' . htmlspecialchars($baseUrl) . '?sort=title"/>
';

        if ($totalPages > 1) {
            if ($page > 1) {
                $firstParams = [];
                if ($sort !== 'title') {
                    $firstParams['sort'] = $sort;
                }
                $firstUrl = $baseUrl;
                if (!empty($firstParams)) {
                    $firstUrl .= '?' . http_build_query($firstParams);
                }
                $xml .= '  <link rel="first" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($firstUrl) . '"/>
';
                
                $prevPage = $page - 1;
                $prevParams = [];
                if ($prevPage > 1) {
                    $prevParams['page'] = $prevPage;
                }
                if ($sort !== 'title') {
                    $prevParams['sort'] = $sort;
                }
                $prevUrl = $baseUrl;
                if (!empty($prevParams)) {
                    $prevUrl .= '?' . http_build_query($prevParams);
                }
                $xml .= '  <link rel="previous" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($prevUrl) . '"/>
';
            }
            
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $nextParams = ['page' => $nextPage];
                if ($sort !== 'title') {
                    $nextParams['sort'] = $sort;
                }
                $nextUrl = $baseUrl . '?' . http_build_query($nextParams);
                $xml .= '  <link rel="next" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($nextUrl) . '"/>
';
                
                $lastParams = ['page' => $totalPages];
                if ($sort !== 'title') {
                    $lastParams['sort'] = $sort;
                }
                $lastUrl = $baseUrl . '?' . http_build_query($lastParams);
                $xml .= '  <link rel="last" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($lastUrl) . '"/>
';
            }
        }

        $xml .= '  
';

        foreach ($books as $book) {
            if ($book !== null) {
                $xml .= $this->generateBookEntry($book, $baseUrl);
            }
        }

        $xml .= '</feed>';
        
        return $xml;
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function authors() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 50)));

        $authors = $this->bookService->getAuthors($page, $perPage, true);
        $totalCount = $this->bookService->getAuthorsCount(true);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $xml = $this->generateAuthorsNavigationXml($authors, $page, $perPage, $totalCount);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function authorBooks($author) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $author = urldecode($author);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        $sort = $this->request->getParam('sort', 'title');
        
        $books = $this->bookService->getBooksByAuthor($author, $page, $perPage, $sort);
        $totalCount = $this->bookService->getBooksByAuthorCount($author);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $title = "Books by " . $author;
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function series() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 50)));
        
        $series = $this->bookService->getSeries($page, $perPage, true);
        $totalCount = $this->bookService->getSeriesCount(true);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $xml = $this->generateSeriesNavigationXml($series, $page, $perPage, $totalCount);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function seriesBooks($seriesName) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $seriesName = urldecode($seriesName);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        
        $books = $this->bookService->getBooksBySeries($seriesName, $page, $perPage);
        $totalCount = $this->bookService->getBooksBySeriesCount($seriesName);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $title = "Series: " . $seriesName;
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function genres() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 50)));
        
        $genres = $this->bookService->getGenres($page, $perPage, true);
        $totalCount = $this->bookService->getGenresCount(true);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $xml = $this->generateGenresNavigationXml($genres, $page, $perPage, $totalCount);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function genreBooks($genre) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $genre = urldecode($genre);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        $sort = $this->request->getParam('sort', 'title');
        
        $books = $this->bookService->getBooksByGenre($genre, $page, $perPage, $sort);
        $totalCount = $this->bookService->getBooksByGenreCount($genre);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $title = "Genre: " . $genre;
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function formats() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 50)));
        
        $formats = $this->bookService->getFormats($page, $perPage, true);
        $totalCount = $this->bookService->getFormatsCount(true);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $xml = $this->generateFormatsNavigationXml($formats, $page, $perPage, $totalCount);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function formatBooks($format) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $format = urldecode($format);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        $sort = $this->request->getParam('sort', 'title');
        
        $books = $this->bookService->getBooksByFormat($format, $page, $perPage, $sort);
        $totalCount = $this->bookService->getBooksByFormatCount($format);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $title = "Format: " . strtoupper($format);
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function languages() {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 50)));
        
        $languages = $this->bookService->getLanguages($page, $perPage, true);
        $totalCount = $this->bookService->getLanguagesCount(true);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $xml = $this->generateLanguagesNavigationXml($languages, $page, $perPage, $totalCount);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[BruteForceProtection(action: 'koreader_opds')]
    public function languageBooks($language) {
        $denied = $this->requireBasicAuth();
        if ($denied !== null) {
            return $denied;
        }
        
        $language = urldecode($language);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = min(100, max(10, (int)$this->request->getParam('per_page', 20)));
        $sort = $this->request->getParam('sort', 'title');
        
        $books = $this->bookService->getBooksByLanguage($language, $page, $perPage, $sort);
        $totalCount = $this->bookService->getBooksByLanguageCount($language);
        
        $validationResponse = $this->validatePagination($page, $perPage, $totalCount);
        if ($validationResponse !== null) {
            return $validationResponse;
        }
        
        $title = "Language: " . $language;
        $xml = $this->generatePaginatedOpdsXml($books, $page, $perPage, $totalCount, $title);
        
        $response = new XMLResponse($xml);
        $response->addHeader('Content-Type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        return $response;
    }


    private function generateAuthorsNavigationXml($authors, $page, $perPage, $totalCount) {
        $baseUrl = $this->getBaseUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '/authors</id>
  <title>Browse by Author</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '/authors"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="up" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
';

        $this->addPaginationLinks($xml, $page, $perPage, $totalCount, $baseUrl . '/authors');

        foreach ($authors as $author) {
            $authorName = htmlspecialchars($author['author']);
            $bookCount = $author['book_count'];
            $authorUrl = $baseUrl . '/authors/' . urlencode($author['author']);
            
            $xml .= '
  <entry>
    <title>' . $authorName . ' (' . $bookCount . ' books)</title>
    <id>' . htmlspecialchars($authorUrl) . '</id>
    <updated>' . $updated . '</updated>
    <link rel="subsection" 
          type="application/atom+xml;profile=opds-catalog" 
          href="' . htmlspecialchars($authorUrl) . '"/>
    <content type="text">Browse ' . $bookCount . ' books by ' . $authorName . '</content>
  </entry>';
        }

        $xml .= '
</feed>';
        
        return $xml;
    }

    private function generateSeriesNavigationXml($series, $page, $perPage, $totalCount) {
        $baseUrl = $this->getBaseUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '/series</id>
  <title>Browse by Series</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '/series"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="up" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
';

        $this->addPaginationLinks($xml, $page, $perPage, $totalCount, $baseUrl . '/series');

        foreach ($series as $seriesItem) {
            $seriesName = htmlspecialchars($seriesItem['series']);
            $bookCount = $seriesItem['book_count'];
            $seriesUrl = $baseUrl . '/series/' . urlencode($seriesItem['series']);
            
            $xml .= '
  <entry>
    <title>' . $seriesName . ' (' . $bookCount . ' books)</title>
    <id>' . htmlspecialchars($seriesUrl) . '</id>
    <updated>' . $updated . '</updated>
    <link rel="subsection" 
          type="application/atom+xml;profile=opds-catalog" 
          href="' . htmlspecialchars($seriesUrl) . '"/>
    <content type="text">Browse ' . $bookCount . ' books in series ' . $seriesName . '</content>
  </entry>';
        }

        $xml .= '
</feed>';
        
        return $xml;
    }

    private function generateGenresNavigationXml($genres, $page, $perPage, $totalCount) {
        $baseUrl = $this->getBaseUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '/genres</id>
  <title>Browse by Genre</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '/genres"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="up" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
';

        $this->addPaginationLinks($xml, $page, $perPage, $totalCount, $baseUrl . '/genres');

        foreach ($genres as $genre) {
            $genreName = htmlspecialchars($genre['subject']);
            $bookCount = $genre['book_count'];
            $genreUrl = $baseUrl . '/genres/' . urlencode($genre['subject']);
            
            $xml .= '
  <entry>
    <title>' . $genreName . ' (' . $bookCount . ' books)</title>
    <id>' . htmlspecialchars($genreUrl) . '</id>
    <updated>' . $updated . '</updated>
    <link rel="subsection" 
          type="application/atom+xml;profile=opds-catalog" 
          href="' . htmlspecialchars($genreUrl) . '"/>
    <content type="text">Browse ' . $bookCount . ' books in genre ' . $genreName . '</content>
  </entry>';
        }

        $xml .= '
</feed>';
        
        return $xml;
    }

    private function generateFormatsNavigationXml($formats, $page, $perPage, $totalCount) {
        $baseUrl = $this->getBaseUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '/formats</id>
  <title>Browse by Format</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '/formats"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="up" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
';

        $this->addPaginationLinks($xml, $page, $perPage, $totalCount, $baseUrl . '/formats');

        foreach ($formats as $format) {
            $formatName = strtoupper(htmlspecialchars($format['file_format']));
            $bookCount = $format['book_count'];
            $formatUrl = $baseUrl . '/formats/' . urlencode($format['file_format']);
            
            $xml .= '
  <entry>
    <title>' . $formatName . ' (' . $bookCount . ' books)</title>
    <id>' . htmlspecialchars($formatUrl) . '</id>
    <updated>' . $updated . '</updated>
    <link rel="subsection" 
          type="application/atom+xml;profile=opds-catalog" 
          href="' . htmlspecialchars($formatUrl) . '"/>
    <content type="text">Browse ' . $bookCount . ' books in ' . $formatName . ' format</content>
  </entry>';
        }

        $xml .= '
</feed>';
        
        return $xml;
    }

    private function generateLanguagesNavigationXml($languages, $page, $perPage, $totalCount) {
        $baseUrl = $this->getBaseUrl();
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>' . htmlspecialchars($baseUrl) . '/languages</id>
  <title>Browse by Language</title>
  <updated>' . $updated . '</updated>
  <author><name>Nextcloud eBooks</name></author>
  
  <link rel="self" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '/languages"/>
  <link rel="start" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
  <link rel="up" type="application/atom+xml;profile=opds-catalog" href="' . htmlspecialchars($baseUrl) . '"/>
';

        $this->addPaginationLinks($xml, $page, $perPage, $totalCount, $baseUrl . '/languages');

        foreach ($languages as $language) {
            $languageName = htmlspecialchars($language['language']);
            $bookCount = $language['book_count'];
            $languageUrl = $baseUrl . '/languages/' . urlencode($language['language']);
            
            $xml .= '
  <entry>
    <title>' . $languageName . ' (' . $bookCount . ' books)</title>
    <id>' . htmlspecialchars($languageUrl) . '</id>
    <updated>' . $updated . '</updated>
    <link rel="subsection" 
          type="application/atom+xml;profile=opds-catalog" 
          href="' . htmlspecialchars($languageUrl) . '"/>
    <content type="text">Browse ' . $bookCount . ' books in ' . $languageName . '</content>
  </entry>';
        }

        $xml .= '
</feed>';
        
        return $xml;
    }

    /**
     * Helper method to add pagination links to navigation feeds
     */
    private function addPaginationLinks(&$xml, $page, $perPage, $totalCount, $baseUrl) {
        $totalPages = ceil($totalCount / $perPage);
        
        if ($totalPages > 1) {
            if ($page > 1) {
                $xml .= '  <link rel="first" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($baseUrl) . '"/>
';
                
                $prevPage = $page - 1;
                $prevUrl = $baseUrl;
                if ($prevPage > 1) {
                    $prevUrl .= '?page=' . $prevPage;
                }
                $xml .= '  <link rel="previous" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($prevUrl) . '"/>
';
            }
            
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $nextUrl = $baseUrl . '?page=' . $nextPage;
                $xml .= '  <link rel="next" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($nextUrl) . '"/>
';
                
                $lastUrl = $baseUrl . '?page=' . $totalPages;
                $xml .= '  <link rel="last" type="application/atom+xml;profile=opds-catalog" 
        href="' . htmlspecialchars($lastUrl) . '"/>
';
            }
        }
    }
}