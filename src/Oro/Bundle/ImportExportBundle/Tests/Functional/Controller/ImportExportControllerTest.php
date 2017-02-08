<?php
namespace Oro\Bundle\ImportExportBundle\Tests\Functional\Controller;

use Oro\Bundle\ContactBundle\Entity\Contact;
use Oro\Bundle\ImportExportBundle\Async\Topics;
use Oro\Bundle\ImportExportBundle\Job\JobExecutor;
use Oro\Bundle\MessageQueueBundle\Test\Functional\MessageQueueExtension;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportExportControllerTest extends WebTestCase
{
    use MessageQueueExtension;

    protected function setUp()
    {
        $this->initClient([], $this->generateBasicAuthHeader());
        $this->client->useHashNavigation(true);
    }

    public function testShouldSendExportMessageOnInstantExportActionWithDefaultParameters()
    {
        $this->client->request(
            'GET',
            $this->getUrl('oro_importexport_export_instant', ['processorAlias' => 'oro_account'])
        );

        $this->assertJsonResponseSuccess();

        $organization = $this->getSecurityFacade()->getOrganization();
        $organizationId = $organization ? $organization->getId() : null;

        $this->assertMessageSent(Topics::EXPORT, [
            'jobName' => JobExecutor::JOB_EXPORT_TO_CSV,
            'processorAlias' => 'oro_account',
            'outputFilePrefix' => null,
            'options' => [],
            'userId' => $this->getCurrentUser()->getId(),
            'organizationId' => $organizationId,
        ]);
    }

    public function testShouldSendExportMessageOnInstantExportActionWithPassedParameters()
    {
        $this->client->request(
            'GET',
            $this->getUrl('oro_importexport_export_instant', [
                'processorAlias' => 'oro_account',
                'exportJob' => JobExecutor::JOB_EXPORT_TEMPLATE_TO_CSV,
                'filePrefix' => 'prefix',
                'options' => [
                    'first' => 'first value',
                    'second' => 'second value',
                ]
            ])
        );

        $this->assertJsonResponseSuccess();

        $organization = $this->getSecurityFacade()->getOrganization();
        $organizationId = $organization ? $organization->getId() : null;

        $this->assertMessageSent(Topics::EXPORT, [
            'jobName' => JobExecutor::JOB_EXPORT_TEMPLATE_TO_CSV,
            'processorAlias' => 'oro_account',
            'outputFilePrefix' => 'prefix',
            'options' => [
                'first' => 'first value',
                'second' => 'second value',
            ],
            'userId' => $this->getCurrentUser()->getId(),
            'organizationId' => $organizationId,
        ]);
    }

    public function testImportProcessAction()
    {
        $options = [
            'first' => 'first value',
            'second' => 'second value',
        ];
        $this->client->request(
            'GET',
            $this->getUrl(
                'oro_importexport_import_process',
                [
                    'processorAlias' => 'oro_account',
                    'importJob' => JobExecutor::JOB_IMPORT_FROM_CSV,
                    'fileName' => 'test_file',
                    'originFileName' => 'test_file_original',
                    'options' => $options,
                ]
            )
        );

        $this->assertJsonResponseSuccess();

        $this->assertMessageSent(
            Topics::IMPORT_HTTP_PREPARING,
            [
                'jobName' => JobExecutor::JOB_IMPORT_FROM_CSV,
                'processorAlias' => 'oro_account',
                'filePath' => 'test_file',
                'originFileName' => 'test_file_original',
                'options' => $options,
                'userId' => $this->getCurrentUser()->getId(),
            ]
        );
    }

    public function testImportValidateAction()
    {
        $options = [
            'first' => 'first value',
            'second' => 'second value',
        ];
        $this->client->request(
            'GET',
            $this->getUrl(
                'oro_importexport_import_validate',
                [
                    'processorAlias' => 'oro_account',
                    'importValidateJob' => JobExecutor::JOB_VALIDATE_IMPORT_FROM_CSV,
                    'fileName' => 'test_file',
                    'originFileName' => 'test_file_original',
                    'options' => $options,
                ]
            )
        );

        $this->assertJsonResponseSuccess();

        $this->assertMessageSent(
            Topics::IMPORT_HTTP_VALIDATION_PREPARING,
            [
                'jobName' => JobExecutor::JOB_VALIDATE_IMPORT_FROM_CSV,
                'processorAlias' => 'oro_account',
                'filePath' => 'test_file',
                'originFileName' => 'test_file_original',
                'options' => $options,
                'userId' => $this->getCurrentUser()->getId(),
            ]
        );
    }

    public function testImportForm()
    {
        $cacheDir = $this->getContainer()->getParameter('kernel.cache_dir');
        $tmpDirName = 'import_export';
        $fileDir = __DIR__ . '/Import/fixtures';
        $file = $fileDir . '/testLineEndings.csv';
        $testFile = $fileDir . '/tmpTestLineEndings.csv';
        copy($file, $testFile);

        $csvFile = new UploadedFile(
            $fileDir . '/tmpTestLineEndings.csv',
            'tmpTestLineEndings.csv',
            'text/csv'
        );
        $this->assertEquals(0, substr_count(file_get_contents($csvFile->getPathname()), "\r\n" ));
        $this->assertEquals(11, substr_count(file_get_contents($csvFile->getPathname()), "\n" ));

        $crawler = $this->client->request(
            'GET',
            $this->getUrl(
                'oro_importexport_import_form',
                [
                    '_widgetContainer' => 'dialog',
                    '_wid' => 'test',
                    'entity' => Contact::class,
                ]
            )
        );
        $this->assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        $uploadFileNode = $crawler->selectButton('Submit');
        $uploadFileForm = $uploadFileNode->form();
        $values = [
            'oro_importexport_import' => [
                '_token' => $uploadFileForm['oro_importexport_import[_token]']->getValue(),
                'processorAlias' => 'oro_contact.add_or_replace'
            ],
        ];
        $files = [
            'oro_importexport_import' => [
                'file' => $csvFile
            ]
        ];

        $this->client->request(
            $uploadFileForm->getMethod(),
            $this->getUrl(
                'oro_importexport_import_form',
                [
                    '_widgetContainer' => 'dialog',
                    '_wid' => 'test',
                    'entity' => Contact::class,
                ]
            ),
            $values,
            $files
        );
        $this->assertJsonResponseSuccess();
        $tmpFiles = glob($cacheDir . DIRECTORY_SEPARATOR . $tmpDirName . DIRECTORY_SEPARATOR . '*.tmp');
        $tmpFile = new File($tmpFiles[count($tmpFiles)-1]);
        $this->assertEquals(11, substr_count(file_get_contents($tmpFile->getPathname()), "\r\n" ));
        unlink($tmpFile->getPathname());
    }

    /**
     * @return object
     */
    private function getSecurityFacade()
    {
        return $this->getContainer()->get('oro_security.security_facade');
    }

    /**
     * @return mixed
     */
    private function getCurrentUser()
    {
        return $this->getContainer()->get('security.token_storage')->getToken()->getUser();
    }

    private function assertJsonResponseSuccess()
    {
        $result = $this->getJsonResponseContent($this->client->getResponse(), 200);

        $this->assertNotEmpty($result);
        $this->assertCount(1, $result);
        $this->assertTrue($result['success']);
    }
}
