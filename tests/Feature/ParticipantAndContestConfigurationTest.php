<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CompetitionScoringMethod;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class ParticipantAndContestConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_be_created_with_a_profile_picture(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Alex Santos',
                'participant_reference' => 'P-100',
                'participant_image' => UploadedFile::fake()->image('alex.jpg'),
            ])
            ->assertRedirect(route('events.show', $event));

        $participant = Participant::query()->sole();

        $this->assertSame('Alex Santos', $participant->name);
        $this->assertNotNull($participant->profile_picture_path);
        Storage::disk('public')->assertExists($participant->profile_picture_path);
    }

    public function test_participant_picture_uses_database_when_public_disk_cannot_write(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $image = UploadedFile::fake()->image('alex.png');
        $expectedContents = file_get_contents($image->getRealPath());
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Alex Santos',
                'participant_image' => $image,
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasNoErrors();

        $participant = Participant::query()->sole();
        $imageId = substr($participant->profile_picture_path, strlen('database:'));
        $this->assertSame('database:'.$imageId, $participant->profile_picture_path);
        $this->assertDatabaseHas('participant_images', [
            'id' => $imageId,
            'event_id' => $event->id,
            'mime_type' => 'image/png',
        ]);
        $this->get(route('events.show', $event))
            ->assertSee(route('participant-images.show', $imageId, false));
        $this->get(route('participant-images.show', $imageId))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSee($expectedContents, false);
    }

    public function test_replacing_database_backed_picture_removes_the_previous_image(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->twice()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Alex Santos',
                'participant_image' => UploadedFile::fake()->image('first.png'),
            ])
            ->assertRedirect();

        $participant = Participant::query()->sole();
        $firstImageId = substr($participant->profile_picture_path, strlen('database:'));

        $this->patch(route('events.participants.update', [$event, $participant]), [
            'participant_name' => 'Alex Updated',
            'participant_image' => UploadedFile::fake()->image('second.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $participant->refresh();
        $secondImageId = substr($participant->profile_picture_path, strlen('database:'));
        $this->assertSame('Alex Updated', $participant->name);
        $this->assertNotSame($firstImageId, $secondImageId);
        $this->assertDatabaseCount('participant_images', 1);
        $this->get(route('participant-images.show', $firstImageId))->assertNotFound();
        $this->get(route('participant-images.show', $secondImageId))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_replacing_legacy_picture_succeeds_when_read_only_disk_cannot_delete_it(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $participant = $event->participants()->create([
            'name' => 'Alex Santos',
            'profile_picture_path' => "participants/{$event->id}/old.jpg",
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('Read-only disk'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->patch(route('events.participants.update', [$event, $participant]), [
                'participant_name' => 'Alex Updated',
                'participant_image' => UploadedFile::fake()->image('replacement.png'),
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasNoErrors();

        $participant->refresh();
        $this->assertSame('Alex Updated', $participant->name);
        $this->assertStringStartsWith('database:', $participant->profile_picture_path);
        $this->assertDatabaseCount('participant_images', 1);
    }

    public function test_oversized_picture_uses_database_after_compression_when_disk_cannot_write(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('Read-only disk'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Large Image',
                'participant_image' => UploadedFile::fake()->image('large.png', 1200, 900)->size(3072),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = Participant::query()->sole();
        $imageId = substr($participant->profile_picture_path, strlen('database:'));
        $stored = DB::table('participant_images')->find($imageId);
        $this->assertNotNull($stored);
        $this->assertLessThanOrEqual(2048 * 1024, strlen(base64_decode($stored->contents, true)));
        $this->get(route('participant-images.show', $imageId))
            ->assertOk()
            ->assertHeader('Content-Type', $stored->mime_type);
    }

    public function test_deleting_participant_removes_database_backed_picture(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Alex Santos',
                'participant_image' => UploadedFile::fake()->image('alex.jpg'),
            ])
            ->assertRedirect();

        $participant = Participant::query()->sole();
        $imageId = substr($participant->profile_picture_path, strlen('database:'));

        $this->delete(route('events.participants.destroy', [$event, $participant]))
            ->assertRedirect(route('events.show', $event));

        $this->assertModelMissing($participant);
        $this->assertDatabaseCount('participant_images', 0);
        $this->get(route('participant-images.show', $imageId))->assertNotFound();
    }

    public function test_oversized_participant_picture_is_compressed_before_storage(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $image = UploadedFile::fake()->image('large.png', 1200, 900)->size(3072);

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Large Image',
                'participant_image' => $image,
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasNoErrors();

        $path = Participant::query()->sole()->profile_picture_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertLessThanOrEqual(2048 * 1024, Storage::disk('public')->size($path));
        $this->assertContains(pathinfo($path, PATHINFO_EXTENSION), ['webp', 'jpg']);
        $this->assertNotFalse(getimagesize(Storage::disk('public')->path($path)));
    }

    public function test_participant_picture_above_upload_limit_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Too Large',
                'participant_image' => UploadedFile::fake()->image('too-large.jpg')->size(10241),
            ])
            ->assertSessionHasErrors('participant_image');

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_participant_picture_above_dimension_limit_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Too Wide',
                'participant_image' => UploadedFile::fake()->image('too-wide.jpg', 6001, 1),
            ])
            ->assertSessionHasErrors('participant_image');

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_subteam_can_be_added_to_a_team_in_the_same_event_but_cannot_be_nested_or_cross_events(): void
    {
        $auditor = User::factory()->create();
        $event = Event::factory()->create();
        $anotherEvent = Event::factory()->create();
        $teamA = $event->participants()->create(['name' => 'TEAM A']);
        $outsideTeam = $anotherEvent->participants()->create(['name' => 'Other Event Team']);

        $this->actingAs($auditor)->post(route('events.participants.store', $event), [
            'participant_name' => 'TEAM A2',
            'parent_participant_id' => $teamA->id,
        ])->assertRedirect(route('events.show', $event));

        $subteam = $event->participants()->where('name', 'TEAM A2')->sole();
        $this->assertSame($teamA->id, $subteam->parent_participant_id);
        $this->actingAs($auditor)->get(route('events.show', $event))
            ->assertSee('Points roll up to TEAM A');

        $this->actingAs($auditor)->post(route('events.participants.store', $event), [
            'participant_name' => 'Invalid nested team',
            'parent_participant_id' => $subteam->id,
        ])->assertSessionHasErrors('parent_participant_id');
        $this->actingAs($auditor)->post(route('events.participants.store', $event), [
            'participant_name' => 'Invalid outside team',
            'parent_participant_id' => $outsideTeam->id,
        ])->assertSessionHasErrors('parent_participant_id');

        $this->assertDatabaseCount('participants', 3);

        $this->actingAs($auditor)->patch(route('events.participants.update', [$event, $teamA]), [
            'participant_name' => 'TEAM A',
            'parent_participant_id' => $subteam->id,
        ])->assertSessionHasErrors('parent_participant_id');

        $this->actingAs($auditor)->delete(route('events.participants.destroy', [$event, $teamA]))
            ->assertSessionHasErrors('participant');
        $this->assertModelExists($teamA);

        $this->actingAs($auditor)->patch(route('events.participants.update', [$event, $subteam]), [
            'participant_name' => 'TEAM A2',
            'parent_participant_id' => '',
        ])->assertRedirect();
        $this->assertNull($subteam->refresh()->parent_participant_id);
    }

    public function test_participant_can_be_edited_and_profile_picture_replaced(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store(
            "participants/{$event->id}",
            'public',
        );
        $participant = $event->participants()->create([
            'name' => 'Old name',
            'reference_no' => 'OLD-1',
            'profile_picture_path' => $oldPath,
        ]);

        $this->actingAs($user)
            ->patch(route('events.participants.update', [$event, $participant]), [
                'participant_name' => 'Updated name',
                'participant_reference' => 'NEW-1',
                'participant_image' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertRedirect(route('events.show', $event));

        $participant->refresh();

        $this->assertSame('Updated name', $participant->name);
        $this->assertSame('NEW-1', $participant->reference_no);
        $this->assertNotSame($oldPath, $participant->profile_picture_path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($participant->profile_picture_path);
    }

    public function test_oversized_replacement_picture_is_compressed_and_old_picture_is_removed(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store("participants/{$event->id}", 'public');
        $participant = $event->participants()->create([
            'name' => 'Contestant',
            'profile_picture_path' => $oldPath,
        ]);

        $this->actingAs($user)
            ->patch(route('events.participants.update', [$event, $participant]), [
                'participant_name' => 'Contestant',
                'participant_image' => UploadedFile::fake()->image('replacement.jpg', 1200, 900)->size(3072),
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasNoErrors();

        $newPath = $participant->refresh()->profile_picture_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertLessThanOrEqual(2048 * 1024, Storage::disk('public')->size($newPath));
    }

    public function test_deleting_participant_also_deletes_profile_picture(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $path = UploadedFile::fake()->image('participant.jpg')->store(
            "participants/{$event->id}",
            'public',
        );
        $participant = $event->participants()->create([
            'name' => 'Delete Me',
            'profile_picture_path' => $path,
        ]);

        $this->actingAs($user)
            ->delete(route('events.participants.destroy', [$event, $participant]))
            ->assertRedirect(route('events.show', $event));

        $this->assertModelMissing($participant);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_configure_criteria_and_rank_scoring_for_a_contest(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $category = $event->categories()->create(['name' => 'Senior']);
        $competition = $category->competitions()->create(['name' => 'Solo']);

        $this->actingAs($user)
            ->post(route('events.categories.competitions.criteria.store', [
                $event,
                $category,
                $competition,
            ]), [
                'criterion_name' => 'Technique',
                'max_score' => 40,
            ])
            ->assertRedirect(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]));

        $this->actingAs($user)
            ->post(route('events.categories.competitions.rank-scores.store', [
                $event,
                $category,
                $competition,
            ]), [
                'rank' => 1,
                'points' => 100,
            ])
            ->assertRedirect(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]));

        $this->assertDatabaseHas('criteria', [
            'competition_id' => $competition->id,
            'name' => 'Technique',
            'max_score' => 40,
        ]);
        $this->assertDatabaseHas('rank_scores', [
            'competition_id' => $competition->id,
            'rank' => 1,
            'points' => 100,
        ]);

        $this->actingAs($user)
            ->get(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]))
            ->assertOk()
            ->assertSee('Technique')
            ->assertSee('Maximum score: 40.00')
            ->assertSee('#1');
    }

    public function test_saving_an_existing_rank_updates_its_points(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $category = $event->categories()->create(['name' => 'Junior']);
        $competition = $category->competitions()->create(['name' => 'Group']);
        $competition->rankScores()->create(['rank' => 1, 'points' => 50]);

        $this->actingAs($user)
            ->post(route('events.categories.competitions.rank-scores.store', [
                $event,
                $category,
                $competition,
            ]), [
                'rank' => 1,
                'points' => 75,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('rank_scores', 1);
        $this->assertDatabaseHas('rank_scores', [
            'competition_id' => $competition->id,
            'rank' => 1,
            'points' => 75,
        ]);
    }

    public function test_admin_can_set_points_for_non_podium_participants(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $category = $event->categories()->create(['name' => 'Open']);
        $competition = $category->competitions()->create(['name' => 'Final']);

        $this->actingAs($user)
            ->patch(route('events.categories.competitions.participation-points.update', [
                $event,
                $category,
                $competition,
            ]), [
                'non_podium_points' => 10,
            ])
            ->assertRedirect(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]));

        $this->assertSame('10.00', $competition->refresh()->non_podium_points);
    }

    public function test_admin_can_switch_a_contest_to_wins_based_scoring(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $category = $event->categories()->create(['name' => 'Team Sports']);
        $competition = $category->competitions()->create(['name' => 'Basketball']);
        $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);

        $this->actingAs($user)
            ->patch(route('events.categories.competitions.scoring-method.update', [
                $event,
                $category,
                $competition,
            ]), [
                'scoring_method' => CompetitionScoringMethod::Wins->value,
            ])
            ->assertRedirect(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]));

        $competition->refresh();

        $this->assertSame(CompetitionScoringMethod::Wins, $competition->scoring_method);
        $this->assertDatabaseHas('criteria', [
            'competition_id' => $competition->id,
            'name' => 'Technique',
        ]);

        $this->actingAs($user)
            ->get(route('events.categories.competitions.show', [
                $event,
                $category,
                $competition,
            ]))
            ->assertOk()
            ->assertSee('Wins-based scoring active')
            ->assertDontSee('Define the items judges will score.');
    }

    public function test_criteria_cannot_be_added_to_a_wins_based_contest(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Volleyball',
            'scoring_method' => CompetitionScoringMethod::Wins,
        ]);

        $this->actingAs($user)
            ->post(route('events.categories.competitions.criteria.store', [
                $event,
                $category,
                $competition,
            ]), [
                'criterion_name' => 'Technique',
                'max_score' => 100,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('criteria', 0);
    }
}
