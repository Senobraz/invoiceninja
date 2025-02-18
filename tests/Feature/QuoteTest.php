<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Quote;
use App\Models\Project;
use Tests\MockAccountData;
use App\Models\ClientContact;
use App\Utils\Traits\MakesHash;
use App\Exceptions\QuoteConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * @test
 * @covers App\Http\Controllers\QuoteController
 */
class QuoteTest extends TestCase
{
    use MakesHash;
    use DatabaseTransactions;
    use MockAccountData;

    public $faker;

    protected function setUp() :void
    {
        parent::setUp();

        Session::start();

        $this->faker = \Faker\Factory::create();

        Model::reguard();

        $this->makeTestData();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );
    }

    public function testQuoteConversion()
    {
        $invoice = $this->quote->service()->convertToInvoice();

        $this->assertInstanceOf('\App\Models\Invoice', $invoice);

        $this->expectException(QuoteConversion::class);

        $invoice = $this->quote->service()->convertToInvoice();


    }

    public function testQuoteDownloadPDF()
    {
        $i = $this->quote->invitations->first();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/quote/{$i->key}/download");

        $response->assertStatus(200);
        $this->assertTrue($response->headers->get('content-type') == 'application/pdf');
    }

    public function testQuoteListApproved()
    {
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get('/api/v1/quotes?client_status=approved');

        $response->assertStatus(200);
    }


    public function testQuoteConvertToProject()
    {
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/quotes/bulk', ['action' => 'convert_to_project', 'ids' => [$this->quote->hashed_id]]);

        $response->assertStatus(200);

        $res = $response->json();

        $this->assertNotNull($res['data'][0]['project_id']);

        $project = Project::find($this->decodePrimaryKey($res['data'][0]['project_id']));

        $this->assertEquals($project->name, ctrans('texts.quote_number_short') . " " . $this->quote->number);
    }

    public function testQuoteList()
    {
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get('/api/v1/quotes');

        $response->assertStatus(200);
    }

    public function testQuoteRESTEndPoints()
    {
        $response = null;

        try {
            $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token,
            ])->get('/api/v1/quotes/'.$this->encodePrimaryKey($this->quote->id));
        } catch (ValidationException $e) {
            $message = json_decode($e->validator->getMessageBag(), 1);
        }

        if ($response) {
            $response->assertStatus(200);
        }

        $this->assertNotNull($response);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get('/api/v1/quotes/'.$this->encodePrimaryKey($this->quote->id).'/edit');

        $response->assertStatus(200);

        $quote_update = [
            'status_id' => Quote::STATUS_APPROVED,
            'client_id' => $this->encodePrimaryKey($this->quote->client_id),
            'number'    => 'Rando',
        ];

        $this->assertNotNull($this->quote);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->put('/api/v1/quotes/'.$this->encodePrimaryKey($this->quote->id), $quote_update);

        $response->assertStatus(200);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->put('/api/v1/quotes/'.$this->encodePrimaryKey($this->quote->id), $quote_update);

        $response->assertStatus(200);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/quotes/', $quote_update);

        $response->assertStatus(302);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->delete('/api/v1/quotes/'.$this->encodePrimaryKey($this->quote->id));

        $response->assertStatus(200);

        $client_contact = ClientContact::whereClientId($this->client->id)->first();

        $data = [
            'client_id' => $this->encodePrimaryKey($this->client->id),
            'date' => '2019-12-14',
            'line_items' => [],
            'invitations' => [
                ['client_contact_id' => $this->encodePrimaryKey($client_contact->id)],
            ],
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/quotes', $data);

        $response->assertStatus(200);
    }
}
