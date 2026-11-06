<?php

namespace Database\Seeders;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo data can only be loaded in the local environment.');
        }

        DB::transaction(function (): void {
            Task::query()->delete();
            Milestone::query()->delete();
            Project::query()->delete();

            $home = Project::create(['name' => 'Home Renovation', 'color' => '#7551ff']);
            $garden = Project::create(['name' => 'Garden', 'color' => '#2d86f5']);
            $course = Project::create(['name' => 'Language Course', 'color' => '#ed5991']);
            $trip = Project::create(['name' => 'Weekend Trip', 'color' => '#f78a36']);
            $event = Project::create(['name' => 'Community Event', 'color' => '#2eb884']);

            $painting = $home->milestones()->create(['name' => 'Painting complete', 'due_date' => '2026-10-30']);
            $furniture = $home->milestones()->create(['name' => 'Furniture delivery', 'due_date' => '2026-11-27']);
            $planting = $garden->milestones()->create(['name' => 'Autumn planting', 'due_date' => '2026-10-30']);
            $landscaping = $garden->milestones()->create(['name' => 'Garden layout', 'due_date' => '2026-11-20']);
            $lessons = $course->milestones()->create(['name' => 'First lessons', 'due_date' => '2026-10-29']);
            $practice = $course->milestones()->create(['name' => 'Conversation practice', 'due_date' => '2026-11-26']);
            $itinerary = $trip->milestones()->create(['name' => 'Itinerary ready', 'due_date' => '2026-11-26']);
            $planning = $event->milestones()->create(['name' => 'Event plan ready', 'due_date' => '2026-11-24']);

            $rows = [
                [$home, 'done', 'Measure the living room', 'Record wall lengths and window dimensions for the furniture plan.', 'high', '2026-10-08', null],
                [$home, 'done', 'Collect paint samples', 'Choose a few warm neutral shades to compare in daylight.', 'medium', '2026-10-15', null],
                [$home, 'done', 'Set a renovation budget', 'Allow for materials, delivery costs and a small contingency.', 'medium', '2026-10-23', null],
                [$home, 'in_review', 'Compare wall colors', 'Check the samples beside the curtains and flooring.', 'low', '2026-10-28', $painting],
                [$home, 'in_progress', 'Prepare the walls for painting', 'Fill small holes and cover the floor before applying primer.', 'high', '2026-10-29', $painting],
                [$home, 'todo', 'Choose a reading chair', 'Compare dimensions, fabric options and delivery times.', 'medium', '2026-11-05', $furniture],
                [$home, 'todo', 'Order storage shelves', 'Select shelves that fit the measured alcove.', 'medium', '2026-11-17', $furniture],
                [$home, 'backlog', 'Plan the hallway lighting', 'Consider a ceiling light and a small lamp near the entrance.', 'medium', '2026-11-24', $furniture],
                [$garden, 'done', 'Clear the vegetable beds', 'Remove spent plants and sort suitable material for composting.', 'medium', '2026-10-13', $planting],
                [$garden, 'done', 'Check the garden tools', 'Clean the hand tools and sharpen the pruning shears.', 'medium', '2026-10-20', null],
                [$garden, 'in_review', 'Select spring bulbs', 'Compare flowering times and suitable planting depths.', 'high', '2026-10-28', $planting],
                [$garden, 'in_progress', 'Prepare the soil', 'Mix compost into the beds before planting.', 'medium', '2026-10-30', $planting],
                [$garden, 'todo', 'Sketch a garden layout', 'Mark sunny areas, paths and space for the herb planters.', 'medium', '2026-11-05', $landscaping],
                [$garden, 'todo', 'Choose herbs for the patio', 'Pick a small selection suited to containers and regular cooking.', 'medium', '2026-11-17', $landscaping],
                [$garden, 'backlog', 'Design a rainwater collection area', 'Find space for a water butt near a downpipe.', 'low', '2026-11-20', $landscaping],
                [$course, 'done', 'Choose a beginner textbook', 'Select a book with short lessons and audio exercises.', 'medium', '2026-10-15', $lessons],
                [$course, 'in_review', 'Review basic greetings', 'Practice introductions and polite expressions aloud.', 'medium', '2026-10-29', $lessons],
                [$course, 'in_progress', 'Complete the first listening exercise', 'Listen twice and note unfamiliar words for later review.', 'medium', '2026-11-03', $practice],
                [$course, 'todo', 'Find a conversation group', 'Look for a weekly session suitable for beginners.', 'medium', '2026-11-10', $practice],
                [$course, 'backlog', 'Make vocabulary cards', 'Start with everyday objects, food and common verbs.', 'low', '2026-11-20', null],
                [$course, 'backlog', 'Plan a weekly study routine', 'Reserve short sessions for reading, listening and speaking.', 'low', '2026-11-26', null],
                [$trip, 'done', 'Choose a destination', 'Compare travel times and places within a comfortable weekend distance.', 'medium', '2026-10-23', null],
                [$trip, 'in_review', 'Compare places to stay', 'Check location, cancellation terms and breakfast options.', 'high', '2026-10-29', $itinerary],
                [$trip, 'in_progress', 'Plan the walking route', 'Connect a few sights with time for breaks along the way.', 'high', '2026-11-03', $itinerary],
                [$trip, 'todo', 'Book train tickets', 'Choose departure times that leave a full day at the destination.', 'medium', '2026-11-12', $itinerary],
                [$trip, 'backlog', 'Prepare a packing list', 'Include comfortable shoes, weather layers and travel documents.', 'medium', '2026-11-26', $itinerary],
                [$event, 'done', 'Reserve the community room', 'Confirm the date, capacity and available tables.', 'medium', '2026-10-20', null],
                [$event, 'todo', 'Draft the invitation', 'Include the event time, venue and a contact for replies.', 'low', '2026-10-27', null],
                [$event, 'todo', 'Plan the refreshments', 'Estimate quantities and include a few dietary options.', 'medium', '2026-11-12', $planning],
                [$event, 'backlog', 'Arrange the room layout', 'Leave clear paths between the tables and the entrance.', 'low', '2026-11-24', $planning],
            ];

            foreach ($rows as [$project, $status, $name, $description, $priority, $date, $milestone]) {
                $project->tasks()->create([
                    'status' => $status,
                    'name' => $name,
                    'description' => $description,
                    'priority' => $priority,
                    'due_date' => $date,
                    'milestone_id' => $milestone?->id,
                ]);
            }
        });

        $this->command?->info('Local demo loaded: 5 projects, 8 milestones and 30 tasks for October and November 2026.');
    }
}
