<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Service;

use Closure;
use Error;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Sirix\InertiaPsr15\Exception\InertiaFlashException;
use Sirix\InertiaPsr15\Exception\InertiaPropResolutionException;
use Sirix\InertiaPsr15\Exception\InertiaRenderingException;
use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;
use Sirix\InertiaPsr15\Model\Page;
use Sirix\InertiaPsr15\Model\ProvidesScrollMetadata;
use Sirix\InertiaPsr15\Service\Inertia;
use Sirix\InertiaPsr15\Service\InertiaFactory;
use Sirix\InertiaPsr15\View\RootViewProviderInterface;
use Throwable;
use TypeError;

use function array_keys;
use function json_decode;

final class InertiaV3Test extends TestCase
{
    public function testProtocolValidationUsesThePackageSpecificException(): void
    {
        $exception = null;

        try {
            Inertia::scroll([], '../data');
        } catch (InvalidInertiaArgumentException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    public function testUnrescuedPropFailuresAreWrappedWithTheirLeafPath(): void
    {
        $original = new RuntimeException('database unavailable');

        try {
            $this->inertia([
                'X-Inertia' => 'true',
            ])->render('Dashboard', [
                'stats' => [
                    'summary' => static function() use ($original) {
                        throw $original;
                    },
                ],
            ]);
            self::fail('The prop resolver failure must be rethrown.');
        } catch (InertiaPropResolutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('stats.summary', $exception->path());
        }
    }

    public function testUnrescuedPropFailuresNormalizeUnsafeKeysBeforeCreatingThePath(): void
    {
        $original = new RuntimeException('database unavailable');

        try {
            $this->inertia([
                'X-Inertia' => 'true',
            ])->render('Dashboard', [
                "prop\r\nforged" => static function() use ($original): never {
                    throw $original;
                },
            ]);
            self::fail('The prop resolver failure must be rethrown.');
        } catch (InertiaPropResolutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('propforged', $exception->path());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->getMessage());
        }
    }

    public function testUnrescuedPropErrorsAndTypeErrorsAreWrapped(): void
    {
        foreach ([new Error('callback error'), new TypeError('callback type error')] as $original) {
            try {
                $this->inertia([
                    'X-Inertia' => 'true',
                ])->render('Dashboard', [
                    'stats' => static function() use ($original): never {
                        throw $original;
                    },
                ]);
                self::fail('The prop resolver failure must be wrapped.');
            } catch (InertiaPropResolutionException $exception) {
                self::assertSame($original, $exception->getPrevious());
                self::assertSame('stats', $exception->path());
            }
        }
    }

    public function testExistingPropResolutionExceptionsAreNotWrappedAgain(): void
    {
        $wrapped = new InertiaPropResolutionException('stats.summary', new RuntimeException('database unavailable'));

        try {
            $this->inertia([
                'X-Inertia' => 'true',
            ])->render('Dashboard', [
                'stats' => [
                    'summary' => static function() use ($wrapped): never {
                        throw $wrapped;
                    },
                ],
            ]);
            self::fail('The existing package exception must be rethrown.');
        } catch (InertiaPropResolutionException $exception) {
            self::assertSame($wrapped, $exception);
        }
    }

    public function testScrollMetadataClosureFailuresUseThePropResolutionBoundary(): void
    {
        $original = new RuntimeException('metadata unavailable');

        try {
            $this->inertia([
                'X-Inertia' => 'true',
            ])->render('Feed', [
                'feed' => Inertia::scroll([
                    'data' => [],
                ], metadata: static function() use ($original): never {
                    throw $original;
                }),
            ]);
            self::fail('The scroll metadata failure must be wrapped.');
        } catch (InertiaPropResolutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('feed', $exception->path());
        }
    }

    public function testScrollMetadataProviderFailuresUseThePropResolutionBoundary(): void
    {
        $original = new RuntimeException('pagination unavailable');
        $metadata = new class($original) implements ProvidesScrollMetadata {
            public function __construct(private readonly RuntimeException $exception) {}

            public function getPageName(): string
            {
                throw $this->exception;
            }

            public function getPreviousPage(): null
            {
                return null;
            }

            public function getNextPage(): null
            {
                return null;
            }

            public function getCurrentPage(): int
            {
                return 1;
            }
        };

        try {
            $this->inertia([
                'X-Inertia' => 'true',
            ])->render('Feed', [
                'feed' => Inertia::scroll([
                    'data' => [],
                ], metadata: $metadata),
            ]);
            self::fail('The scroll metadata provider failure must be wrapped.');
        } catch (InertiaPropResolutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('feed', $exception->path());
        }
    }

    public function testDirectFlashUsesTheTopLevelPageField(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->flash('message', 'Saved')->flash([
            'id'      => 42,
            'message' => 'Updated',
        ]);

        $page = $this->page($inertia->render('Dashboard', [
            'flash' => [
                'ordinary' => true,
            ],
        ]));

        self::assertSame([
            'message' => 'Updated',
            'id'      => 42,
        ], $page['flash']);
        self::assertSame([
            'ordinary' => true,
        ], $page['props']['flash']);
    }

    public function testDirectFlashIsAvailableToTheHtmlRootView(): void
    {
        $root = new class implements RootViewProviderInterface {
            public ?Page $page = null;

            public function __invoke(Page $page): string
            {
                $this->page = $page;

                return '<html></html>';
            }
        };
        $inertia = new Inertia(
            new ServerRequest([], [], '/users', 'GET', 'php://memory'),
            new ResponseFactory(),
            new StreamFactory(),
            $root
        );

        $response = $inertia->flash('message', 'Saved')->render('Users');

        self::assertSame('<html></html>', (string) $response->getBody());
        self::assertSame([
            'message' => 'Saved',
        ], $root->page?->getFlash());
    }

    public function testFlashResolverIsLazyCachedAndMergedWithDirectFlash(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $calls = 0;
        $inertia->setFlashResolver(static function() use (&$calls): array {
            ++$calls;

            return [
                'message' => 'Incoming',
                'notice'  => 'Welcome',
            ];
        });
        $inertia->flash('message', 'Current');

        self::assertSame([
            'message' => 'Current',
            'notice'  => 'Welcome',
        ], $this->page($inertia->render('Dashboard'))['flash']);

        $inertia->flash('extra', true);
        self::assertSame([
            'message' => 'Current',
            'notice'  => 'Welcome',
            'extra'   => true,
        ], $this->page($inertia->render('Dashboard'))['flash']);
        self::assertSame(1, $calls);
    }

    public function testFlashResolverFailuresAreWrappedOnceAndKeepTheirCause(): void
    {
        $original = new RuntimeException('flash storage unavailable');
        $inertia  = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->setFlashResolver(static function() use ($original): array {
            throw $original;
        });

        try {
            $inertia->render('Dashboard');
            self::fail('A flash resolver failure must be rethrown.');
        } catch (InertiaFlashException $exception) {
            self::assertSame('pull', $exception->operation());
            self::assertSame($original, $exception->getPrevious());
        }

        $wrapped = new InertiaFlashException('pull', $original);
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->setFlashResolver(static function() use ($wrapped): array {
            throw $wrapped;
        });

        try {
            $inertia->render('Dashboard');
            self::fail('An existing flash exception must be rethrown unchanged.');
        } catch (InertiaFlashException $exception) {
            self::assertSame($wrapped, $exception);
        }
    }

    public function testFlashResolverFailureIsCachedAcrossRepeatedRenderAttempts(): void
    {
        $calls    = 0;
        $original = new RuntimeException('flash storage unavailable');
        $inertia  = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->setFlashResolver(static function() use (&$calls, $original): array {
            ++$calls;

            throw $original;
        });

        $failures = [];
        foreach ([1, 2] as $_) {
            try {
                $inertia->render('Dashboard');
                self::fail('A flash resolver failure must be rethrown.');
            } catch (InertiaFlashException $exception) {
                $failures[] = $exception;
            }
        }

        self::assertSame(1, $calls);
        self::assertSame($failures[0], $failures[1]);
        self::assertSame($original, $failures[0]->getPrevious());
    }

    public function testFlashResolverRejectsNumericIncomingFlashKeys(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->setFlashResolver($this->numericFlashKeyResolver());

        try {
            $inertia->render('Dashboard');
            self::fail('Incoming flash must be an object with safe string keys.');
        } catch (InertiaFlashException $exception) {
            self::assertSame('pull', $exception->operation());
            self::assertInstanceOf(InvalidInertiaArgumentException::class, $exception->getPrevious());
        }
    }

    public function testFlashRejectsUnsafeKeys(): void
    {
        $this->expectException(InvalidInertiaArgumentException::class);
        $this->inertia([
            'X-Inertia' => 'true',
        ])->flash("message\r\nInjected", 'Saved');
    }

    public function testFlashRejectsNonStringKeysAndTreatsAnEmptyArrayAsANoOp(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);

        try {
            $inertia->flash([
                42 => 'Saved',
            ]);
            self::fail('Flash keys must be strings.');
        } catch (InvalidInertiaArgumentException) {
            self::addToAssertionCount(1);
        }

        $page = $this->page($inertia->flash([])->render('Dashboard'));

        self::assertArrayNotHasKey('flash', $page);
    }

    public function testSerializesV3MetadataAndNeverInvokesPlainCallableStrings(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->encryptHistory();
        $inertia->clearHistory();
        $inertia->preserveFragment();
        $inertia->share('auth.user', [
            'id' => 1,
        ]);

        $page = $this->page($inertia->render('Feed', [
            'later'   => Inertia::defer(fn () => ['safe'], 'sidebar'),
            'posts'   => Inertia::merge([
                'data' => [[
                    'id' => 1,
                ]],
            ])->append('data', 'id'),
            'plans'   => Inertia::once(fn () => ['basic'])->as('all-plans'),
            'literal' => 'phpinfo',
            'feed'    => Inertia::scroll([
                'data'         => [],
                'current_page' => 1,
                'next_page'    => 2,
            ]),
        ]));

        self::assertSame('phpinfo', $page['props']['literal']);
        self::assertSame([
            'sidebar' => ['later'],
        ], $page['deferredProps']);
        self::assertSame(['posts.data', 'feed.data'], $page['mergeProps']);
        self::assertSame(['posts.data.id'], $page['matchPropsOn']);
        self::assertSame(['auth'], $page['sharedProps']);
        self::assertSame([
            'prop'      => 'plans',
            'expiresAt' => null,
        ], $page['onceProps']['all-plans']);
        self::assertSame([
            'pageName'     => 'page',
            'previousPage' => null,
            'nextPage'     => 2,
            'currentPage'  => 1,
        ], $page['scrollProps']['feed']);
        self::assertTrue($page['encryptHistory']);
        self::assertTrue($page['clearHistory']);
        self::assertTrue($page['preserveFragment']);
    }

    public function testMergeUsesTheRootMergeOperationByDefaultAndDeepMergeReplacesIt(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard', [
            'users'    => Inertia::merge([[
                'id' => 1,
            ]]),
            'settings' => Inertia::deepMerge([
                'theme' => 'dark',
            ]),
        ]));

        self::assertSame(['users'], $page['mergeProps']);
        self::assertSame(['settings'], $page['deepMergeProps']);
    }

    public function testNestedMergeAndPrependOperationsReplaceTheRootMergeFallback(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard', [
            'users'       => Inertia::merge([])->append('data'),
            'tags'        => Inertia::merge([])->prepend('data'),
            'latestUsers' => Inertia::merge([])->prepend(),
        ]));

        self::assertSame(['users.data'], $page['mergeProps']);
        self::assertSame(['tags.data', 'latestUsers'], $page['prependProps']);
    }

    public function testMergeOperationChainsPublishOnlyOneRootDirection(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard', [
            'appendThenPrepend' => Inertia::merge([])->append()->prepend(),
            'prependThenAppend' => Inertia::merge([])->prepend()->append(),
            'nestedThenPrepend' => Inertia::merge([])->append('data')->prepend(),
        ]));

        self::assertSame(['prependThenAppend', 'nestedThenPrepend.data'], $page['mergeProps']);
        self::assertSame(['appendThenPrepend'], $page['prependProps']);
    }

    public function testResolvesPropWrappersReturnedByClosures(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard', [
            'optional' => static fn () => Inertia::optional(static fn () => 'not loaded'),
            'merged'   => static fn () => Inertia::merge([[
                'id' => 1,
            ]]),
        ]));

        self::assertArrayNotHasKey('optional', $page['props']);
        self::assertSame([[
            'id' => 1,
        ]], $page['props']['merged']);
        self::assertSame(['merged'], $page['mergeProps']);
    }

    public function testUsesARelativeRootUrlForAnAbsoluteRootRequestUri(): void
    {
        $request = new ServerRequest([], [], 'https://example.test', 'GET', 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $root = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };

        $page = $this->page((new Inertia($request, new ResponseFactory(), new StreamFactory(), $root))->render('Dashboard'));

        self::assertSame('/', $page['url']);
    }

    public function testRejectsBlankVersionsPassedToTheConstructorAndFactory(): void
    {
        $request = new ServerRequest([], [], '/users', 'GET', 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $root = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };

        foreach ([
            static fn () => new Inertia($request, new ResponseFactory(), new StreamFactory(), $root, ' '),
            static fn () => (new InertiaFactory(new ResponseFactory(), new StreamFactory(), $root))->fromRequest($request, ''),
        ] as $create) {
            try {
                $create();
                self::fail('Blank versions must be rejected at every public construction boundary.');
            } catch (InvalidInertiaArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsNonRedirectStatusForStringLocations(): void
    {
        foreach ([200, 304] as $status) {
            try {
                $this->inertia([])->location('/dashboard', $status);
                self::fail('Only redirect status codes are valid for string locations.');
            } catch (InvalidInertiaArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testResolvesExplicitDeferredPropsRescuesFailuresAndSkipsKnownOnceProps(): void
    {
        $inertia = $this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'deferred,broken,plans',
            'X-Inertia-Except-Once-Props' => 'plans',
        ]);
        $page = $this->page($inertia->render('Dashboard', [
            'deferred' => Inertia::defer(fn () => 'loaded'),
            'broken'   => Inertia::defer(static function(): never {
                throw new RuntimeException('sensitive failure');
            }, rescue: true),
            'plans'    => Inertia::once(fn () => ['must not run']),
        ]));

        self::assertSame('loaded', $page['props']['deferred']);
        self::assertArrayNotHasKey('broken', $page['props']);
        self::assertSame(['must not run'], $page['props']['plans']); // Explicit partial reload refreshes once props.
        self::assertSame(['broken'], $page['rescuedProps']);
        self::assertSame([
            'prop'      => 'plans',
            'expiresAt' => null,
        ], $page['onceProps']['plans']);
    }

    public function testKnownOncePropIsNotResolvedOnARegularVisit(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Except-Once-Props' => 'plans',
        ])->render('Billing', [
            'plans' => Inertia::once(static function(): never {
                throw new RuntimeException('must not resolve');
            }),
        ]));

        self::assertArrayNotHasKey('plans', $page['props']);
        self::assertSame([
            'prop'      => 'plans',
            'expiresAt' => null,
        ], $page['onceProps']['plans']);
    }

    public function testRejectsUnsafeWrapperInput(): void
    {
        $this->expectException(InvalidInertiaArgumentException::class);
        Inertia::scroll([], '../data');
    }

    public function testRejectsUnsafeMergePathsAndScrollMetadata(): void
    {
        foreach ([
            static fn () => Inertia::merge([])->append("items\r\nX-Evil: yes"),
            static fn () => Inertia::merge([])->append('items', "id\x00"),
            static fn () => Inertia::scroll([], metadata: [
                'pageName'     => "page\nInjected",
                'previousPage' => null,
                'nextPage'     => null,
                'currentPage'  => 1,
            ])->metadata([]),
        ] as $factory) {
            try {
                $factory();
                self::fail('Unsafe protocol metadata must be rejected.');
            } catch (InvalidInertiaArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsControlCharactersInInertiaRedirectLocations(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);

        $this->expectException(InvalidInertiaArgumentException::class);
        $inertia->location("/safe\r\nX-Evil: yes");
    }

    public function testRejectsUnsafeDeferredGroupsOnceKeysAndNegativeTtls(): void
    {
        foreach ([
            static fn () => Inertia::defer(static fn () => null, "group\ninvalid"),
            static fn () => Inertia::once(null)->as("cache\rkey"),
            static fn () => Inertia::once(null)->until(-1),
        ] as $factory) {
            try {
                $factory();
                self::fail('Unsafe once/deferred metadata must be rejected.');
            } catch (InvalidInertiaArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnserializablePageDataUsesThePackageSerializationException(): void
    {
        $recursive         = [];
        $recursive['self'] = &$recursive;

        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->flash('recursive', $recursive);

        try {
            $inertia->render('Users');
            self::fail('Recursive flash data must not be serialized.');
        } catch (InertiaSerializationException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    public function testJsonSerializableFailuresAreWrappedWithTheirExactCause(): void
    {
        foreach ([new RuntimeException('serialization failed'), new Error('serialization error')] as $original) {
            $value = new class($original) implements JsonSerializable {
                public function __construct(private readonly Throwable $exception) {}

                public function jsonSerialize(): mixed
                {
                    throw $this->exception;
                }
            };

            try {
                $this->inertia([
                    'X-Inertia' => 'true',
                ])->render('Users', [
                    'value' => $value,
                ]);
                self::fail('JsonSerializable failures must be wrapped.');
            } catch (InertiaSerializationException $exception) {
                self::assertSame($original, $exception->getPrevious());
            }
        }
    }

    public function testInvalidUtf8InFlashIsSubstitutedRatherThanFailingSerialization(): void
    {
        $response = $this->inertia([
            'X-Inertia' => 'true',
        ])->flash('message', "Invalid \xB1")
            ->render('Dashboard')
        ;

        self::assertSame('Invalid �', $this->page($response)['flash']['message']);
    }

    public function testRootViewFailuresUseThePackageRenderingException(): void
    {
        $request = new ServerRequest([], [], '/users', 'GET', 'php://memory');
        $root    = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                throw new RuntimeException('template unavailable');
            }
        };
        $inertia = new Inertia($request, new ResponseFactory(), new StreamFactory(), $root);

        $this->expectException(InertiaRenderingException::class);
        $inertia->render('Users');
    }

    public function testRootViewPreservesSerializationExceptions(): void
    {
        $original = new InertiaSerializationException('Unable to serialize the Inertia page.');
        $root     = new class($original) implements RootViewProviderInterface {
            public function __construct(private readonly InertiaSerializationException $exception) {}

            public function __invoke(Page $page): string
            {
                throw $this->exception;
            }
        };
        $inertia = new Inertia(
            new ServerRequest([], [], '/users', 'GET', 'php://memory'),
            new ResponseFactory(),
            new StreamFactory(),
            $root
        );

        try {
            $inertia->render('Users');
            self::fail('Serialization exceptions must not be wrapped as rendering failures.');
        } catch (InertiaSerializationException $exception) {
            self::assertSame($original, $exception);
        }
    }

    public function testInvalidPartialHeaderPathsCannotSelectOptionalProps(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Users',
            'X-Inertia-Partial-Data'      => 'users..email',
        ])->render('Users', [
            'users' => Inertia::optional(static function(): never {
                throw new RuntimeException('must not resolve');
            }),
        ]));

        self::assertArrayNotHasKey('users', $page['props']);
    }

    public function testUnpacksSharedAndPageDottedPropsAndAlwaysIncludesErrors(): void
    {
        $inertia = $this->inertia([
            'X-Inertia' => 'true',
        ]);
        $inertia->share('auth.user', [
            'id' => 1,
        ]);
        $inertia->shareOnce('auth.countries', fn () => ['UA']);

        $page = $this->page($inertia->render('Dashboard', [
            'auth.name' => 'Jane',
            'errors'    => [
                'email' => 'Invalid',
            ],
        ]));

        self::assertSame([
            'user'      => [
                'id' => 1,
            ],
            'countries' => ['UA'],
            'name'      => 'Jane',
        ], $page['props']['auth']);
        self::assertSame([
            'email' => 'Invalid',
        ], $page['props']['errors']);
        self::assertSame(['auth'], $page['sharedProps']);
        self::assertArrayHasKey('auth.countries', $page['onceProps']);

        $partial = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'name',
        ])->render('Dashboard', [
            'name' => 'Jane',
        ]));
        self::assertSame([], $partial['props']['errors']);
    }

    public function testCollectsMetadataForDeferredAndOptionalWrapperChains(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard', [
            'activity' => Inertia::defer(fn () => [
                'id' => 1,
            ])->deepMerge()->once(),
            'settings' => Inertia::optional(fn () => [
                'dark' => true,
            ])->once(),
        ]));

        self::assertSame([
            'default' => ['activity'],
        ], $page['deferredProps']);
        self::assertSame(['activity'], $page['deepMergeProps']);
        self::assertSame(['activity', 'settings'], array_keys($page['onceProps']));
        self::assertArrayNotHasKey('activity', $page['props']);
        self::assertArrayNotHasKey('settings', $page['props']);
    }

    public function testScrollResetSuppressesMergeAndEmitsResetMetadata(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Feed',
            'X-Inertia-Partial-Data'      => 'feed',
            'X-Inertia-Reset'             => 'feed',
        ])->render('Feed', [
            'feed' => Inertia::scroll([
                'data'         => [],
                'current_page' => 1,
            ]),
        ]));

        self::assertArrayNotHasKey('mergeProps', $page);
        self::assertTrue($page['scrollProps']['feed']['reset']);
    }

    public function testOnceKeysRejectWhitespaceAndCommaSeparators(): void
    {
        foreach ([' plans', 'plans ', 'plans,other'] as $key) {
            try {
                Inertia::once(null)->as($key);
                self::fail('Unsafe once key must be rejected.');
            } catch (InvalidInertiaArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCachedDeferredOncePropDoesNotAdvertiseDeferredReload(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Except-Once-Props' => 'stats',
        ])->render('Dashboard', [
            'stats' => Inertia::defer(static function(): never {
                throw new RuntimeException('must not resolve');
            })->once(),
        ]));

        self::assertArrayNotHasKey('stats', $page['props']);
        self::assertArrayNotHasKey('deferredProps', $page);
        self::assertArrayHasKey('stats', $page['onceProps']);
    }

    public function testNestedPartialDeferredRescueDoesNotBypassTheWrapper(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'auth.user',
        ])->render('Dashboard', [
            'auth' => Inertia::defer(static function(): never {
                throw new RuntimeException('must be rescued');
            }, rescue: true),
        ]));

        self::assertArrayNotHasKey('auth', $page['props']);
        self::assertSame(['auth'], $page['rescuedProps']);
    }

    public function testDefaultErrorsIsAnEmptyJsonObject(): void
    {
        $response = $this->inertia([
            'X-Inertia' => 'true',
        ])->render('Dashboard');

        self::assertStringContainsString('"errors":{}', (string) $response->getBody());
    }

    public function testNestedPartialExceptResolvesOptionalWrapperThenExcludesTheRequestedChild(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Except'    => 'auth.user',
        ])->render('Dashboard', [
            'auth' => Inertia::optional(fn () => [
                'user'         => 'hidden',
                'internalFlag' => true,
            ]),
        ]));

        self::assertSame([
            'internalFlag' => true,
        ], $page['props']['auth']);
    }

    public function testNestedPartialExceptRescuesADeferredWrapperFailure(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Except'    => 'auth.user',
        ])->render('Dashboard', [
            'auth' => Inertia::defer(static function(): never {
                throw new RuntimeException('must be rescued');
            }, rescue: true),
        ]));

        self::assertArrayNotHasKey('auth', $page['props']);
        self::assertSame(['auth'], $page['rescuedProps']);
    }

    public function testPartialOnlyThenExceptFiltersTopLevelAndNestedPathsInOrder(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'projects,auth.user,auth.notifications',
            'X-Inertia-Partial-Except'    => 'projects,auth.notifications',
        ])->render('Dashboard', [
            'projects' => ['one'],
            'auth'     => static fn (): array => [
                'user'          => 'Jane',
                'notifications' => ['New message'],
                'role'          => 'admin',
            ],
            'settings' => [
                'theme' => 'dark',
            ],
        ]));

        self::assertSame([
            'errors' => [],
            'auth'   => [
                'user' => 'Jane',
            ],
        ], $page['props']);
    }

    public function testPartialOnlyDoesNotReintroduceUnrequestedSharedProps(): void
    {
        $inertia = $this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'auth.user',
        ]);
        $inertia->share('shared.flash', 'Saved');

        $page = $this->page($inertia->render('Dashboard', [
            'auth' => [
                'user'  => 'Jane',
                'token' => 'secret',
            ],
        ]));

        self::assertSame([
            'errors' => [],
            'auth'   => [
                'user' => 'Jane',
            ],
        ], $page['props']);
    }

    public function testPartialOnlyThenExceptDoesNotReintroduceNestedSharedProps(): void
    {
        $inertia = $this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'auth',
            'X-Inertia-Partial-Except'    => 'auth.user',
        ]);
        $inertia->share('auth.user', [
            'name'  => 'Jane',
            'email' => 'jane@example.com',
        ]);
        $inertia->share('auth.refresh_token', 'value');

        $page = $this->page($inertia->render('Dashboard'));

        self::assertSame([
            'errors' => [],
            'auth'   => [
                'refresh_token' => 'value',
            ],
        ], $page['props']);
    }

    public function testPartialOnlyThenExceptDoesNotResolveAWhollyExcludedNestedBranch(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'auth.user',
            'X-Inertia-Partial-Except'    => 'auth.user',
        ])->render('Dashboard', [
            'auth' => static function(): never {
                throw new RuntimeException('must not resolve');
            },
        ]));

        self::assertSame([
            'errors' => [],
        ], $page['props']);
    }

    public function testAlwaysPropIgnoresPartialOnlyAndExceptFilters(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'projects',
            'X-Inertia-Partial-Except'    => 'timestamp',
        ])->render('Dashboard', [
            'projects'  => ['one'],
            'timestamp' => Inertia::always(static fn (): string => '2026-08-15T08:00:00Z'),
            'settings'  => [
                'theme' => 'dark',
            ],
        ]));

        self::assertSame([
            'errors'    => [],
            'projects'  => ['one'],
            'timestamp' => '2026-08-15T08:00:00Z',
        ], $page['props']);
    }

    public function testPartialFiltersAreIgnoredWhenTheComponentChanges(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data'      => 'projects',
            'X-Inertia-Partial-Except'    => 'settings',
        ])->render('Login', [
            'projects' => ['one'],
            'settings' => [
                'theme' => 'dark',
            ],
        ]));

        self::assertSame([
            'errors'   => [],
            'projects' => ['one'],
            'settings' => [
                'theme' => 'dark',
            ],
        ], $page['props']);
    }

    public function testPartialScrollPrependIntentUsesPrependMetadata(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                              => 'true',
            'X-Inertia-Partial-Component'            => 'Feed',
            'X-Inertia-Partial-Data'                 => 'feed',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])->render('Feed', [
            'feed' => Inertia::scroll([
                'data'         => [],
                'current_page' => 2,
                'prev_page'    => 1,
            ]),
        ]));

        self::assertSame(['feed.data'], $page['prependProps']);
        self::assertArrayNotHasKey('mergeProps', $page);
        self::assertSame(1, $page['scrollProps']['feed']['previousPage']);
    }

    public function testFreshOncePropOverridesTheClientOnceCache(): void
    {
        $page = $this->page($this->inertia([
            'X-Inertia'                   => 'true',
            'X-Inertia-Except-Once-Props' => 'plans',
        ])->render('Billing', [
            'plans' => Inertia::once(fn () => ['fresh'])->fresh(),
        ]));

        self::assertSame(['fresh'], $page['props']['plans']);
        self::assertSame('plans', $page['onceProps']['plans']['prop']);
    }

    public function testVersionPassedToTheConstructorIsSerializedIntoThePage(): void
    {
        $request = new ServerRequest([], [], '/users', 'GET', 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $root = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $inertia = new Inertia($request, new ResponseFactory(), new StreamFactory(), $root, 'v42');

        $page = $this->page($inertia->render('Users'));

        self::assertSame('v42', $page['version']);
    }

    public function testFactoryVersionArgumentIsSerializedIntoThePage(): void
    {
        $request = new ServerRequest([], [], '/users', 'GET', 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $root = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $root);
        $inertia = $factory->fromRequest($request, 'v42');

        $page = $this->page($inertia->render('Users'));

        self::assertSame('v42', $page['version']);
    }

    /** @param array<non-empty-string, array<string>|string> $headers */
    private function inertia(array $headers): Inertia
    {
        $request = new ServerRequest([], [], '/users?filter=active', 'GET', 'php://memory', $headers);
        $root    = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };

        return new Inertia($request, new ResponseFactory(), new StreamFactory(), $root);
    }

    private function numericFlashKeyResolver(): Closure
    {
        return static fn (): array => ['notice'];
    }

    /** @return array<string, mixed> */
    private function page(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
