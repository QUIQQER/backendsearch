<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI\BackendSearch\Provider\Media;
use QUI\BackendSearch\Provider\Sites;
use QUI\BackendSearch\Provider\UsersAndGroups;

class ProviderBehaviorTest extends TestCase
{
    public function testMediaGetEntryBuildsExpectedPayload(): void
    {
        $Provider = new Media();
        $entry = $Provider->getEntry('demo-42');

        $this->assertArrayHasKey('searchdata', $entry);

        $searchData = json_decode((string)$entry['searchdata'], true);
        $this->assertIsArray($searchData);
        $this->assertSame('package/quiqqer/backendsearch/bin/controls/provider/Media', $searchData['require']);
        $this->assertSame('demo', $searchData['params']['project']);
        $this->assertSame('42', $searchData['params']['id']);
    }

    public function testSitesGetEntryBuildsExpectedPayload(): void
    {
        $Provider = new Sites();
        $entry = $Provider->getEntry('myProject-de-123');

        $this->assertArrayHasKey('searchdata', $entry);

        $searchData = json_decode((string)$entry['searchdata'], true);
        $this->assertIsArray($searchData);
        $this->assertSame('package/quiqqer/backendsearch/bin/controls/provider/Sites', $searchData['require']);
        $this->assertSame('myProject', $searchData['params']['projectName']);
        $this->assertSame('de', $searchData['params']['projectLang']);
        $this->assertSame('123', $searchData['params']['siteId']);
    }

    public function testUsersAndGroupsGetEntryBuildsExpectedPayload(): void
    {
        $Provider = new UsersAndGroups();

        $userEntry = $Provider->getEntry('u99');
        $groupEntry = $Provider->getEntry('g17');

        $this->assertSame('package/quiqqer/backendsearch/bin/controls/provider/UsersAndGroups', $userEntry['searchdata']['require']);
        $this->assertSame('99', $userEntry['searchdata']['params']['id']);
        $this->assertSame('user', $userEntry['searchdata']['params']['type']);

        $this->assertSame('17', $groupEntry['searchdata']['params']['id']);
        $this->assertSame('group', $groupEntry['searchdata']['params']['type']);
    }

    public function testStaticFilterGroupsOfMediaAndSites(): void
    {
        $Media = new Media();
        $Sites = new Sites();

        $mediaGroups = array_map(static function (array $group): string {
            return (string)$group['group'];
        }, $Media->getFilterGroups());

        $siteGroups = array_map(static function (array $group): string {
            return (string)$group['group'];
        }, $Sites->getFilterGroups());

        $this->assertContains('folder', $mediaGroups);
        $this->assertContains('image', $mediaGroups);
        $this->assertContains('file', $mediaGroups);
        $this->assertSame(['sites'], $siteGroups);
    }

    public function testSitesSearchReturnsEmptyIfSitesFilterIsMissing(): void
    {
        $Provider = new Sites();

        $result = $Provider->search('foo', [
            'filterGroups' => ['image', 'file']
        ]);

        $this->assertSame([], $result);
    }

    public function testUsersAndGroupsSearchReturnsEmptyWhenFilteredOut(): void
    {
        $Provider = new UsersAndGroups();

        $result = $Provider->search('foo', [
            'filterGroups' => ['not-users-groups']
        ]);

        $this->assertSame([], $result);
    }
}
