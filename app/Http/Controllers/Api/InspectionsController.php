<?php

namespace App\Http\Controllers\Api;

use App\Category;
use App\Hive;
use App\HiveType;
use App\BeeRace;
use App\Inspection;
use App\InspectionItem;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Moment\Moment;
use Auth;
use LaravelLocalization;

class InspectionsController extends Controller
{
    
    public function lists(Request $request)
    {
        $out               = [];
        $checklists        = $request->user()->checklists();
        $out['checklists'] = $checklists->orderBy('name')->get();
        
        $checklist    = null;

        if ($checklists->where('id',intval($request->input('id')))->count() > 0)
            $checklist = $checklists->where('id',intval($request->input('id')))->first();
        else
            $checklist = $request->user()->checklists()->orderBy('created_at', 'desc')->first();
    
        if ($checklist && $checklist->categories()->count() > 0)
            $checklist->categories = $checklist->categories()->get()->toTree();

        $out['checklist']  = $checklist;

        return response()->json($out);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function hive(Request $request, $hive_id)
    {
        $hive   = $request->user()->hives()->findOrFail($hive_id);
        $locale = $request->has('locale') ? $request->input('locale') : LaravelLocalization::getCurrentLocale();

        $inspections   = $hive->inspections_by_date();
        $items_by_date = $hive->inspection_items_by_date($locale);

        return response()->json(['inspections'=>$inspections, 'items_by_date'=>$items_by_date]);
    }

    public function show(Request $request, $id)
    {
        $inspection = $request->user()->inspections()->find($id);
        if (isset($inspection) == false)
            response()->json(null, 404);

        $inspection_items= InspectionItem::where('inspection_id',$inspection->id)->groupBy('category_id')->get();
        $inspection->items = $inspection_items;
        return response()->json($inspection);
    }


    public function store(Request $request)
    {
        if ($request->has('date') && $request->has('items'))
        {
            $moment       = new Moment($request->input('date'));
            $date         = $moment->format('Y-m-d H:i:s');
            
            $data         = $request->except(['hive_id','items','date']);
            $user         = Auth::user();
            $hive         = $user->hives()->find($request->input('hive_id'));
            $location     = $user->locations()->find($request->input('location_id'));

            $data['created_at'] = $date;

            if ($request->has('reminder_date'))
            {
                $reminder_moment = new Moment($request->input('reminder_date'));
                $data['reminder_date'] = $reminder_moment->format('Y-m-d H:i:s');
            }

            if ($hive)
                $inspection = $hive->inspections()->orderBy('created_at','desc')->whereDate('created_at', $moment->format('Y-m-d'))->first();
            else if ($location)
                $inspection = $location->inspections()->orderBy('created_at','desc')->whereDate('created_at', $moment->format('Y-m-d'))->first();
            else
                $inspection = $user->inspections()->orderBy('created_at','desc')->whereDate('created_at', $moment->format('Y-m-d'))->first();

            // filter -1 values for impression and attention
            $data['impression'] = $request->has('impression') && $request->input('impression') > -1 ? $request->input('impression') : null;
            $data['attention']  = $request->has('attention')  && $request->input('attention')  > -1 ? $request->input('attention')  : null;

            //die(print_r($data));


            if (isset($inspection))
                $inspection->update($data);
            else
                $inspection = Inspection::create($data);
            
            // link inspection
            $inspection->users()->syncWithoutDetaching($user->id);

            if (isset($location))
                $inspection->locations()->syncWithoutDetaching($location->id);

            if (isset($hive))
                $inspection->hives()->syncWithoutDetaching($hive->id);


            // Set inspection items
            //die(print_r($request->input('items')));
            // clear to remove items not in input
            $inspection->items()->forceDelete();
            // add items in input
            foreach ($request->input('items') as $cat_id => $value) 
            {
                // $inspectionItem = $inspection->items()->where('category_id',$cat_id)->first();

                // if ($inspectionItem)
                // {
                //     $inspectionItem->value = $value;
                //     $inspectionItem->save();
                // }
                // else
                // {
                    $category = Category::find($cat_id);
                    if (isset($category) && isset($value))
                    {
                        $itemData = 
                        [
                            'category_id'   => $category->id,
                            'inspection_id' => $inspection->id,
                            'value'         => $value,
                        ];
                        InspectionItem::create($itemData);
                    }
                // }
            }
            // Delete removed inspection items
            // $remove = array_intersect($inspection->items()->pluck('category_id')->toArray(), array_keys($request->input('items')));
            // die(print_r($remove));

        }
        if (isset($inspection))
            return response()->json($inspection->id, 201);
            //return $this->show($request, $condition);

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Location  $location
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        Auth::user()->inspections()->findOrFail($id)->delete();
    }

}
