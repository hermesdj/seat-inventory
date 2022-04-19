<?php

namespace RecursiveTree\Seat\Inventory\Http\Controllers;

use RecursiveTree\Seat\Inventory\Helpers\DoctrineCategorySyncHelper;
use RecursiveTree\Seat\Inventory\Helpers\FittingPluginHelper;
use RecursiveTree\Seat\Inventory\Helpers\ItemHelper;
use RecursiveTree\Seat\Inventory\Helpers\LocationHelper;
use RecursiveTree\Seat\Inventory\Helpers\Parser;
use RecursiveTree\Seat\Inventory\Helpers\StockHelper;
use RecursiveTree\Seat\Inventory\Jobs\GenerateStockIcon;
use RecursiveTree\Seat\Inventory\Jobs\UpdateStockLevels;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\Inventory\Models\Stock;
use RecursiveTree\Seat\Inventory\Models\StockCategory;

use RecursiveTree\Seat\Inventory\Models\StockItem;
use Seat\Web\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;

class InventoryController extends Controller
{
    private function redirectWithStatus($request,$redirect,$message,$type){
        $request->session()->flash('message', [
            'message' => $message,
            'type' => $type
        ]);
        return redirect()->route($redirect);
    }

    public function dashboard(Request $request){


        return view("inventory::dashboard");
    }

    public function getCategories(Request $request){
        $request->validate([
            'location' =>'nullable|integer'
        ]);

        $categories = StockCategory::with("stocks","stocks.location")->get();

        $location_id = $request->location;
        if($location_id) { // 0 stands for all locations
            $categories = $categories->filter(function ($category) use ($location_id) {
                return $category->stocks()->where("location_id", $location_id)->exists();
            });
        }

        return response()->json($categories->values());
    }

    public function locationLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        $query = Location::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $locations = $query->get();

        $suggestions = $locations->map(function ($location){
            return [
                'id' => $location->id,
                'text' => $location->name
            ];
        });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function stockSuggestion(Request $request){
        $request->validate([
            "term"=>"nullable|string",
        ]);

        $location_ids = null;
        if($request->term){
            $location_ids = Location::where("name","like","%$request->term%")->pluck("id");
        }

        $query = Stock::query();
        if($request->term){
            $query->where("name", "like", "%$request->term%");
        }
        if($location_ids) {
            $query->orWhereIn("location_id", $location_ids);
        }
        $stocks = $query->get();

        $suggestions = $stocks
            ->map(function ($stock){
                $location = $stock->location->name;
                return [
                    'id' => $stock,
                    'text' => "$stock->name --- $location"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function saveCategory(Request $request){
        $request->validate([
            "name" => "required|string|max:64",
            "id" => "nullable|integer",
            "stocks" => "required|array",
            "stocks.*" => "integer",
            "filters" => "required|array",
        ]);

        $category = StockCategory::find($request->id);
        if(!$category){
            $category = new StockCategory();
        }

        foreach ($request->stocks as $id){
            $stock = Stock::find($id);
            if(!$stock) {
                return response()->json([],400);
            }
        }

        $category->name = $request->name;
        $category->filters = json_encode($request->filters);
        $category->save();

        //save stocks after category so the category has an id when creating a new category
        $category->stocks()->sync($request->stocks);

        return response()->json();
    }

    public function deleteCategory(Request $request){
        $request->validate([
            "id" => "required|integer"
        ]);

        $category = StockCategory::find($request->id);
        if(!$category){
            return $this->redirectWithStatus($request,'inventory.main',"Could not find category!", 'error');
        }

        //remove all linked stocks
        $category->stocks()->detach();
        //actually delete it
        StockCategory::destroy($request->id);

        return response()->json();
    }

    public function removeStockFromCategory(Request $request){
        $request->validate([
            "stock"=>"required|integer",
            "category"=>"required|integer"
        ]);

        $category = StockCategory::find($request->category);
        if(!$category){
            return response()->json([],400);
        }

        $category->stocks()->detach($request->stock);

        //go back
        return response()->json();
    }

    public function deleteStock(Request $request,$id){
        $request->validate([
            "id"=>"required|integer"
        ]);

        $stock = Stock::find($id);

        if(!$stock){
            return response()->json([],400);
        }

        //delete categories
        $stock->categories()->detach();
        //delete items
        StockItem::where("stock_id", $id)->delete();
        //delete the stock itself
        Stock::destroy($id);

        return response()->json();
    }

    public function saveStock(Request $request){

        //validation
        $request->validate([
            "id"=>"nullable|integer",
            "location"=>"required|integer",
            "amount"=>"required|integer|gt:0",
            "warning_threshold"=>"required|integer|gte:0",
            "priority"=>"required|integer|gte:0|lte:5",
            "check_contracts"=>"required|boolean",
            "check_hangars"=>"required|boolean",
            "fit"=>"nullable|string",
            "multibuy"=>"nullable|string",
            "name"=>"required_with:multibuy|string"
        ]);

        //additional fields
        $name = $request->name ?: "unnamed";
        $items = [];

        //validate location
        $location = Location::find($request->location);
        if(!$location) return response()->json(["message"=>"location not found"],400);

        //validate type
        if($request->multibuy === null && $request->fit===null) return response()->json(["message"=>"neither fit nor multibuy found"],400);

        //validate fit/get items
        if($request->fit){
            try {
                $fit = Parser::parseFit($request->fit);
            } catch (Exception $e){
                $message = $e->getMessage();
                return response()->json(["message"=>"Failed to parse fit: $message"],400);
            }
            $name = $fit["name"];
            $items = $fit["items"];
        }

        //validate multibuy
        if($request->multibuy){
            $items = Parser::parseMultiBuy($request->multibuy);
        }

        //data filling stage

        //make sure there aren't any duplicate item stacks
        $items = ItemHelper::simplifyItemList($items);

        //get the stock
        $stock = Stock::findOrNew($request->id);

        //use a transaction to roll it back if anything fails
        DB::transaction(function () use ($stock, $items, $name, $request) {
            $stock->name = $name;
            $stock->location_id = $request->location;
            $stock->amount = $request->amount;
            $stock->warning_threshold = $request->warning_threshold;
            $stock->priority = $request->priority;
            $stock->check_contracts = $request->check_contracts;
            $stock->check_corporation_hangars = $request->check_hangars;

            //make sure we get an id
            $stock->save();

            //remove old items
            $stock->items()->delete();

            $stock->items()->saveMany(array_map(function ($item) use ($stock) {
                return $item->asStockItem($stock->id);
            },$items));
        });

        //data update phase

        //update stock levels for new stock
        UpdateStockLevels::dispatch($location->id)->onQueue('default');

        //if it is in a doctrine, we have to add categories
        DoctrineCategorySyncHelper::syncStock($stock);

        //generate a new icon
        GenerateStockIcon::dispatch($stock->id,null);

        return response()->json();
    }

    public function doctrineLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        if(!FittingPluginHelper::pluginIsAvailable()){
            return response()->json(["results"=>[]]);
        }

        $query = FittingPluginHelper::$FITTING_PLUGIN_DOCTRINE_MODEL::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $suggestions = $query->get();

        $suggestions = $suggestions
            ->map(function ($doctrine){
                return [
                    'id' => $doctrine->id,
                    'text' => "$doctrine->name"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function about(){
        return view("inventory::about");
    }

    public function stockIcon($id){
        $stock = Stock::findOrFail($id);

        return Image::make($stock->getIcon())->response();
    }
}