<?php

namespace eLife\Journal\Controller;

use eLife\ApiSdk\Collection\Sequence;
use eLife\ApiSdk\Model\Collection;
use eLife\Journal\Helper\Callback;
use eLife\Journal\Helper\HasPages;
use eLife\Journal\Helper\Paginator;
use eLife\Patterns\ViewModel\ContentHeader;
use eLife\Patterns\ViewModel\ListHeading;
use eLife\Patterns\ViewModel\ListingProfileSnippets;
use eLife\Patterns\ViewModel\ListingTeasers;
use eLife\Patterns\ViewModel\ProfileSnippet;
use eLife\Patterns\ViewModel\Teaser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CollectionsController extends Controller
{
    use HasPages;

    public function listAction(Request $request) : Response
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = 10;

        $arguments = $this->defaultPageArguments($request);

        $latestResearch = $this->pagerfantaPromise(
            $this->get('elife.api_sdk.collections'),
            $page,
            $perPage
        );

        $arguments['title'] = 'Collections';

        $arguments['paginator'] = $this->paginator(
            $latestResearch,
            $request,
            'Browse our collections',
            'collections'
        );

        $arguments['listing'] = $arguments['paginator']
            ->then($this->willConvertTo(ListingTeasers::class, ['emptyText' => 'No collections available.']));

        if (1 === $page) {
            return $this->createFirstPage($arguments);
        }

        return $this->createSubsequentPage($request, $arguments);
    }

    private function createFirstPage(array $arguments) : Response
    {
        $arguments['contentHeader'] = new ContentHeader($arguments['title']);

        return new Response($this->get('templating')->render('::collections.html.twig', $arguments));
    }

    public function collectionAction(Request $request, string $id) : Response
    {
        $collection = $this->get('elife.api_sdk.collections')
            ->get($id)
            ->otherwise($this->mightNotExist())
            ->then($this->checkSlug($request, Callback::method('getTitle')));

        $arguments = $this->defaultPageArguments($request, $collection);

        $arguments['title'] = $collection
            ->then(Callback::method('getTitle'));

        $arguments['collection'] = $collection;

        $arguments['contentHeader'] = $arguments['collection']
            ->then($this->willConvertTo(ContentHeader::class));

        $arguments['body'] = $arguments['collection']
            ->then(function (Collection $collection) {
                if ($collection->getSummary()->notEmpty()) {
                    yield from $collection->getSummary()->map($this->willConvertTo());
                }

                yield ListingTeasers::basic(
                    $collection->getContent()->map($this->willConvertTo(Teaser::class))->toArray(),
                    new ListHeading('Collection')
                );
            });

        $arguments['multimedia'] = $arguments['collection']
            ->then(Callback::method('getPodcastEpisodes'))
            ->then(Callback::emptyOr(function (Sequence $podcastEpisodes) {
                return ListingTeasers::basic(
                    $podcastEpisodes->map($this->willConvertTo(Teaser::class, ['variant' => 'secondary']))->toArray(),
                    new ListHeading('Multimedia')
                );
            }));

        $arguments['related'] = $arguments['collection']
            ->then(Callback::method('getRelatedContent'))
            ->then(Callback::emptyOr(function (Sequence $relatedContent) {
                return ListingTeasers::basic(
                    $relatedContent->map($this->willConvertTo(Teaser::class, ['variant' => 'secondary']))->toArray(),
                    new ListHeading('Related')
                );
            }));

        $arguments['contributors'] = $arguments['collection']
            ->then(Callback::method('getCurators'))
            ->then(function (Sequence $curators) {
                return ListingProfileSnippets::basic(
                    $curators->map($this->willConvertTo(ProfileSnippet::class))->toArray(),
                    new ListHeading('Contributors')
                );
            });

        return new Response($this->get('templating')->render('::collection.html.twig', $arguments));
    }
}
