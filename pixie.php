<!DOCTYPE html>
<html>
	<head>
		<link href="https://fonts.googleapis.com/css?family=Harmattan" rel="stylesheet">
		<link rel="stylesheet" href="spectrum/spectrum.css" />
		<link rel="stylesheet" href="font-awesome/css/font-awesome.css"/>
		<link rel="stylesheet" href="pixie.css"/>
		<script type="text/javascript" src="jquery.min.js"></script>
		<script type="text/javascript" src='matrices.js'></script>
		<script type="text/javascript" src='spectrum/spectrum.js'></script>
		<script type="text/javascript" src="FileSaver/FileSaver.min.js"></script>
		<script type="text/javascript">
			var currentTool = 'pencil';
			var mouse, frames = [], activeFrame = 0;
			var cells, colours, drawColour, eraseColour;
			var symmetry = null;
			var colours = [];
			var canvas, context;
			var optimalScale = {
				width : 500,
				height : 500,
				minCellSize : 5
			};

			var gridInfo = {
				w : 29,
				h : 29,
				cellWidth : 0,
				cellHeight : 0,
				showing : true
			};


			var brushes = [
				new matrixClass([[1]]),
				new matrixClass([
						[0, 1, 0], 
						[1, 1, 1], 
						[0, 1, 0]
					]),
				new matrixClass([
						[ 0, 1, 1, 0],
						[ 1, 1, 1, 1],
						[ 1, 1, 1, 1],
						[ 0, 1, 1, 0]
					]),
				new matrixClass([
						[ 0, 1, 1, 1, 0],
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1],
						[ 0, 1, 1, 1, 0]
					]),
				new matrixClass([
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1],
						[ 1, 1, 1, 1, 1]
					]),
				new matrixClass([
						[ 1, 0, 1, 0, 1],
						[ 0, 0, 0, 0, 0],
						[ 1, 0, 1, 0, 1],
						[ 0, 0, 0, 0, 0],
						[ 1, 0, 1, 0, 1]
					]),
				new matrixClass([
						[1, 0, 0, 0, 0, 0, 0],
						[0, 0, 0, 0, 0, 0, 0],
						[0, 0, 1, 0, 0, 0, 0],
						[0, 0, 0, 0, 0, 0, 0],
						[0, 0, 0, 0, 1, 0, 0],
						[0, 0, 0, 0, 0, 0, 0],
						[0, 0, 0, 0, 0, 0, 1]
					]),
				new matrixClass([
						[0, 0, 0, 0, 0, 0, 1],
						[0, 0, 0, 0, 0, 0, 0],
						[0, 0, 0, 0, 1, 0, 0],
						[0, 0, 0, 0, 0, 0, 0],
						[0, 0, 1, 0, 0, 0, 0],
						[0, 0, 0, 0, 0, 0, 0],
						[1, 0, 0, 0, 0, 0, 0]
					])
			];
			var currentBrush = 0

			function renderBrushButton(idx){
				var brushScale = 2;
				var brush = brushes[idx];
				var x, y, rval;
				var canvas = $('<canvas></canvas>')[0];
				canvas.width = brush.width * brushScale;
				canvas.height = brush.height * brushScale;
				ctx = canvas.getContext('2d');
				ctx.fillStyle = 'rgba(0, 0, 0, 255)';
				for(x = 0; x < brush.width; x++){
					for(y = 0; y < brush.height; y++){
						if(brush.val(x, y)){
							ctx.fillRect(x * brushScale, y * brushScale, brushScale, brushScale);
						}
					}
				}
				rval = $('<a></a>');
				rval.addClass('brushButton');
				rval.css('line-heigtht', brush.height * 2);
				rval.append(canvas);
				rval.click(function(){
					$('.brushButton').each(function(){
						$(this).removeClass('activeTool')
					});
					$(this).addClass('activeTool')
					currentBrush = idx;
					return false;
				});
				return rval;
			}

			var state = (function(){
				var pastStates = [];
				var futureStates = [];

				var stateClass = function(){
					this.frames = [];
				};

				stateClass.prototype.capture = function(){
					var n;
					for(n in frames){
						this.frames[n] = frames[n].capture();
					}
				};

				stateClass.prototype.restore = function(){
					var oldSymmetry = symmetry;
					var n;
					for(n = 0; n < frames.length; n++){
						frames[n].destroy();
						frames[n] = null;
					}
					frames = [];
					$('#canvasHolder').empty();

					for(n = 0; n < this.frames.length; n++){
						frames[frames.length] = frameClass.restoreFromState(this.frames[n]);
					}
					if(!(activeFrame in frames)){
						activeFrame = 0;
					}
					frames[activeFrame].refreshCells();
					symmetry = oldSymmetry;
				};

				return {
					capture : function(){
						var state = new stateClass();
						state.capture();
						pastStates[pastStates.length] = state;
					},
					undo : function(){
						if(pastStates.length == 0) return;
						var state = pastStates.pop();
						state.restore();
						futureStates[futureStates.length] = state;
					},
					redo : function(){
						if(futureStates.length == 0) return;
						var state = futureStates.pop();
						state.restore();
						pastStates[pastStates.length] = state;
					}
				};
			})();

			/******* the frame class ********/
			var frameClass = function(gridInfo){
				this.cells = [];
				this.canvas = $('<canvas></canvas>');
				this.canvas.attr('id', 'thumbnail');
				this.canvas.appendTo($('#canvasHolder'));
				this.canvas.attr('width', gridInfo.w + 'px');
				this.canvas.attr('height', gridInfo.h + 'px');

				this.context = this.canvas[0].getContext('2d');

				this.width = gridInfo.w;
				this.height = gridInfo.h;

			};

			// calculate the best available cell size for the current frame dimensions
			frameClass.prototype.calcCellSize = function(){
				var newCellWidth = optimalScale.width / this.width;
				var newCellHeight = optimalScale.height / this.height;
				var newCellSize = Math.round(newCellWidth > newCellHeight ? newCellHeight : newCellWidth);
				if(newCellSize < optimalScale.minCellSize) newCellSize = optimalScale.minCellSize;
				gridInfo.cellWidth = gridInfo.cellHeight = newCellSize;
			};

			frameClass.restoreFromState = function(data){
				var x, y, pixel;
				var rval = new frameClass({w : data.width, h : data.height});
				for(x = 0; x < rval.width; x++){
					rval.cells[x] = [];
					for(y = 0; y < rval.height; y++){
						rval.cells[x][y] = new cellClass(x, y);
						dat = data.cells[x][y]
						if(dat != null){
							rval.cells[x][y].colour = data.cells[x][y];
						}
					}
				}
				for(y = 0; y < rval.height; y++){
					for(x = 0; x < rval.width; x++){
						rval.cells[x][y].draw($('#editgrid'));
					}
				}
				refreshGrid();

				return rval;
			};

			frameClass.prototype.refreshFromCanvas = function(){
				var x, y, c;
				this.width = gridInfo.w = this.canvas.width();
				this.height = gridInfo.h = this.canvas.height();
				this.calcCellSize();
				renderGrid();
				for(x = 0; x < this.width; x++){
					for(y = 0; y < this.height; y++){
						c = getCanvasPixel(x, y);
						this.cells[x][y].element.css('background-color', c);

						this.cells[x][y].colour = stringToRGBA(getCanvasPixel(x, y));
					}
				}
				
			}


			frameClass.prototype.destroy = function(){
				this.canvas.remove();
				for(y = 0; y < this.cells[0].length; y++){
					for(x = 0; x < this.cells.length; x++){
						this.cells[x][y].destroy();
					}
				}
				this.context = null;
				return;
			};

			frameClass.prototype.capture = function(){
				var rval = {
					'width' : this.width,
					'height' : this.height,
					'cells' : []
				};
				var x, y, c;
				for(x = 0; x < this.cells.length; x++){
					rval.cells[x] = [];
					for(y = 0; y < this.cells[x].length; y++){
						c = this.cells[x][y].colour;

						rval.cells[x][y] = c == null ? null : c;
					}
				}
				return rval;
			};

			frameClass.prototype.resetCanvas = function(){
				if(this.canvas.jquery){
					this.canvas.remove();
				}
				this.width = gridInfo.w;
				this.height = gridInfo.h;
				this.canvas = $('<canvas></canvas>');
				this.canvas.attr('id', 'thumbnail');
				this.canvas.appendTo($('#canvasHolder'));
				this.canvas.attr('width', this.width + 'px');
				this.canvas.attr('height', this.height + 'px');
				this.context = this.canvas[0].getContext('2d');
				this.refreshCells();
				$('#palette').css({
					'height' : $('#editgrid').height() + 'px'
				});
			};

			frameClass.prototype.refreshCells = function(){
				var x, y;
				for(x = 0; x < gridInfo.w; x++){
					for(y = 0; y < gridInfo.h; y++){
						this.cells[x][y].refresh();
					}
				}
			};

			frameClass.prototype.rebuild = function(){
				var x, y;
				$('#editgrid').empty();
				for(y = 0; y < this.cells[0].length; y++){
					for(x = 0; x < this.cells.length; x++){
						this.cells[x][y].element.appendTo($('#editgrid'));
					}
				}
			}

			frameClass.prototype.download = function(){
				this.canvas[0].toBlob(function(blob) {
					saveAs(blob, $('#filename').val() + '.png');
				});
			}

			frameClass.prototype.clear = function(){
				var x, y;
				state.capture();
				for(x = 0; x < gridInfo.w; x++){
					for(y = 0; y < gridInfo.h; y++){
						this.cells[x][y].setColour(null);
					}
				}
			};

			function renderGrid(){
				$('#editgrid').empty();
				$('#editgrid').width(gridInfo.w  * gridInfo.cellWidth);
				$('#editgrid').height(gridInfo.h  * gridInfo.cellHeight);
				frames[activeFrame].cells = [];
				for(x = 0; x < gridInfo.w; x++){
					frames[activeFrame].cells[x] = [];
					for(y = 0; y < gridInfo.h; y++){
						frames[activeFrame].cells[x][y] = new cellClass(x, y);
					}
				}
				for(y = 0; y < gridInfo.h; y++){
					for(x = 0; x < gridInfo.w; x++){
						frames[activeFrame].cells[x][y].draw($('#editgrid'));
					}
				}
			}

			var mouse = {
				state : {current : 0, last : 0},
				gridPosition : { x : null, y : null},
				lastPosition : { x : null, y : null}
			};


			/******* the cell class ********/
			var cellClass = function(x, y, colour){
				var me = this;
				this.width = gridInfo.cellWidth - 1;
				this.height = gridInfo.cellHeight - 1;
				this.colour = null;
				this.position = {
					'x' : x,
					'y' : y
				};

				var n;

				this.element = $('<div></div>');
				this.element.addClass('editcell');
				this.element.width(this.width);
				this.element.height(this.height);
				this.highlight = null;
			};

			cellClass.prototype.destroy = function(){
				this.element.remove();
				if(this.highlight != null){
					this.highlight.remove();
				}
			};

			cellClass.prototype.draw = function(target){
				this.element.appendTo(target);
			};

			cellClass.prototype.setHighlight = function(colour){

			};

			cellClass.prototype.setColour = function(colour){
				if(colour == null){
					this.colour = null;
				}else if(colour.rgba != undefined){
					this.colour = colour.rgba;
				}else if(colour.red != undefined){
					this.colour = colour;
				}else if(typeof colour == "string"){
					this.colour = stringToRGBA(colour);
				}else{
					throw "cellClass::setColour: Invalid colour type";
				}
				this.refresh();
			};

			cellClass.prototype.refresh = function(){
				var colour = this.highlight == null ? this.colour : this.highlight;

				if(colour == null || (typeof(colour) != 'object')){
					this.element.css('background', 'none');
					frames[activeFrame].context.clearRect(this.position.x, this.position.y, 1, 1);
				}else{
					colour = rgbaToString(colour);
					this.element.css('background-color', colour);

					frames[activeFrame].context.clearRect(this.position.x, this.position.y, 1, 1);
					frames[activeFrame].context.fillStyle = colour;
					frames[activeFrame].context.fillRect(this.position.x, this.position.y, 1, 1);
				}
			}

			function getCanvasPixel(x, y){
				var vals = frames[activeFrame].context.getImageData(x, y, 1, 1).data;
				return 'rgba(' + vals[0] + ', ' + vals[1] + ', ' + vals[2] + ', ' + vals[3] + ')';
			}


			/******* the colour class ********/
			var colourClass = function(){
				var n;
				this.element = $('<div></div>');
				this.rgba = {
					red : 255,
					green : 255,
					blue : 255,
					alpha : 1
				};
				if(arguments.length == 1 && typeof(arguments[0]) == 'object'){
					for(n in arguments[0]){
						if(n in this.rgba){
							this.rgba[n] = arguments[0][n];
						}
					}
				}
			};

			colourClass.prototype.renderButton = function(){
				var colour = rgbaToString(this.rgba);
				if(this.rgba.red + this.rgba.green + this.rgba.blue > 384){
					var compliment = 'rgba(0,0,0,1)';
				}else{
					var compliment = 'rgba(255,255,255,1)';
				}
				this.element.css({
					'background-color' : colour,
					'color' : compliment,
				});
				this.element.addClass('colourButton');
				return this.element;
			};

			colourClass.prototype.addButton = function(target){
				var element = this.renderButton(), me = this;
				if(target != undefined){
					target.append(element);
				}


				// add the delete button
				this.deleteButton = $('<i class="fa fa-trash-o" aria-hidden="true"></i>');
				this.deleteButton.addClass('colourActionButton');
				element.append(this.deleteButton);
				var me = this;
				this.deleteButton.click(function(){
					me.destroy();
					return false;
				});


				// add the edit button
				this.editButton = $('<i class="fa fa-pencil-square-o" aria-hidden="true"></i>');
				this.editButton.addClass('colourActionButton');
				this.editButton.appendTo(element);
				this.editButton.spectrum({
					'preferredFormat' : "rgb",
					'showAlpha' : true,
					'showInput' : true,
					'color' : rgbaToString(this.rgba),
					'hide' : function(colour){
						var oldRGBA = me.rgba;
						var n, x, y;
						me.setRGBA(colour.toString());


						if(typeof drawColour == 'object' && drawColour != null){
							drawColour.element.removeClass('selectedDrawColour');
						}
						drawColour = me;
						drawColour.element.addClass('selectedDrawColour');

						for(n = 0; n < frames.length; n++){
							for(x = 0; x < frames[n].cells.length; x++){
								for(y = 0; y < frames[n].cells[x].length; y++){
									if(
										frames[n].cells[x][y].colour != null &&
										frames[n].cells[x][y].colour['red'] == oldRGBA.red &&
										frames[n].cells[x][y].colour['green'] == oldRGBA.green &&
										frames[n].cells[x][y].colour['blue'] == oldRGBA.blue &&
										frames[n].cells[x][y].colour['alpha'] == oldRGBA.alpha
									){
										frames[n].cells[x][y].setColour(me.rgba);
										frames[n].cells[x][y].refresh();
									}
								}
							}
						}
					}
				});
				// add the copy button
				this.copyButton = $('<i class="fa fa-clone" aria-hidden="true"></i>');
				this.copyButton.addClass('colourActionButton');
				this.copyButton.appendTo(element);
				this.copyButton.click(function(){
					me.duplicate();
				});

				// handle the right click
				element.contextmenu(function(evt){
					if(typeof eraseColour == 'object' && eraseColour != null){
						eraseColour.element.removeClass('selectedEraseColour');
					}
					if(eraseColour == me){
						eraseColour = null;
					}else{
						eraseColour = me;
						eraseColour.element.addClass('selectedEraseColour');
					}
					return false;
				});

				// add the actual selection of this colour
				element.click(function(evt){
					if(evt.which == 1){
						if(typeof drawColour == 'object' && drawColour != null){
							drawColour.element.removeClass('selectedDrawColour');
						}
						if(drawColour == me){
							drawColour = null;
						}else{
							drawColour = me;
							drawColour.element.addClass('selectedDrawColour');
						}
					}
					return false;
				});
				return element;
			};

			colourClass.prototype.duplicate = function(){
				var c = new colourClass(this.rgba);
				var button = c.addButton();
				var pos = colours.indexOf(this);
				button.insertAfter(this.element);
				colours.splice(pos, 0, c);
			};

			colourClass.prototype.setRGBA = function(rgba){
				this.element.css('background-color', rgba);
				var parts = rgba.substring(rgba.indexOf('(') + 1, rgba.lastIndexOf(')')).split(/,\s*/);
				if(parts.length == 3){
					parts[parts.length] = 1;
				}
				this.rgba = {
					'red' : 1 * parts[0],
					'green' : 1 * parts[1],
					'blue' : 1 * parts[2],
					'alpha' : 1 * parts[3]
				};

				if(this.rgba.red + this.rgba.green + this.rgba.blue > 384){
					var compliment = 'rgba(0,0,0,1)';
				}else{
					var compliment = 'rgba(255,255,255,1)';
				}
				this.element.css({
					'color' : compliment
				});
			};

			colourClass.find = function(red, green, blue, alpha){
				var n, rval = null;
				for(n in colours){
					if(
						colours[n].rgba.red == red &&
						colours[n].rgba.green == green &&
						colours[n].rgba.blue == blue &&
						colours[n].rgba.alpha == alpha
					){
						rval = colours[n];
						break;
					}
				}
				return rval;
			};

			colourClass.createColour = function(red, green, blue, alpha){
				c = new colourClass({
					'red' : red,
					'green' : green,
					'blue' : blue,
					'alpha' : alpha
				});
				c.addButton($('#palette'));
				colours[colours.length] = c;
				return c;
			};

			colourClass.prototype.destroy = function(){
				if(this == drawColour) drawColour = null;
				if(this == eraseColour) eraseColour == null;
				this.element.remove();
				var n;
				for(n = 0; n < colours.length && colours[n] != this; n++);
				for(; n < colours.length - 1; n++){
					colours[n] = colours[n + 1];
				}
				if(n < colours.length){
					colours.pop();
				}
			};

			function rgbaToString(rgba){
				//debugger;
				return 'rgba(' + rgba.red + ', ' + rgba.green + ', ' + rgba.blue + ', ' + rgba.alpha + ')';
			}

			function stringToRGBA(str){
				var parts = str.substring(str.indexOf('(') + 1, str.lastIndexOf(')')).split(/,\s*/);
				if(parts.length == 3){
					parts[parts.length] = 1;
				}
				return {
					'red' : parts[0],
					'green' : parts[1],
					'blue' : parts[2],
					'alpha' : parts[3]
				};
			}

			function handleMouseAction(evt){
				// find the appropriate cell at that location
				var gridPos = $('#editgrid').position();
				var x = Math.floor((evt.pageX - gridPos.left) / gridInfo.cellWidth);
				var y = Math.floor((evt.pageY - gridPos.top) / gridInfo.cellHeight);
				if(!(x in frames[activeFrame].cells) || !(y in frames[activeFrame].cells[x])){
					$('#error').append("[[INVALID CELL CO-ORDINATE: (" + x + ', ' + y + ")]]");
					return;
				}
				mouse.lastPosition = mouse.gridPosition;
				mouse.gridPosition = {'x' : x, 'y' : y};
				useCurrentTool();

			}

			function useCurrentTool(){
				switch(currentTool){
					case 'pencil':
						tool_pencil();
						break;
					case 'paintbrush':
						tool_paintbrush();
						break;
					case 'floodfill':
						tool_floodfill();
						break;
					case 'sample':
						tool_sample();
						break;
					default:
						console.log("Unimplemented Tool: " + currentTool);
				}
			}

			function setTool(tool){
				var validTools = {pencil:null, paintbrush:null, floodfill:null, sample:null};
				if(!(tool in validTools)){
					throw 'Invalid tool "' + tool + '"';
				}

				if(currentTool in validTools){
					$('#tool_' + currentTool).removeClass('currentTool');
				}

				currentTool = tool;
				$('#tool_' + currentTool).addClass('currentTool');

			}

			function tool_sample(){
				if(mouse.state.current == 1){
					sampleColour = getPixel(mouse.gridPosition.x, mouse.gridPosition.y);
					if(sampleColour == null){
						drawColour = null;
					}
					if(sampleColour != drawColour){

						var c = colourClass.find(sampleColour);
						if(c == null){
							var parts = stringToRGBA(sampleColour);
							var c = colourClass.createColour(parts.red, parts.green, parts.blue, parts.alpha);
						}
						c.element.click();


						if(typeof drawColour == 'object' && drawColour != null){
							drawColour.element.removeClass('selectedDrawColour');
						}
						drawColour = sampleColour;
//						drawColour.element.addClass('selectedDrawColour');
					}
				}
			}

			function tool_pencil(){
				var colour;
				if(mouse.state.current == 1){
					colour = drawColour;
				}else if(mouse.state.current == 3){
					colour = eraseColour;
				}else{
					return;
				}
				if(mouse.state.current != mouse.state.last){
					state.capture();
				}

				applyBrush(mouse.gridPosition.x, mouse.gridPosition.y, colour);
			}

			function tool_paintbrush(){
				var colour;
				if(mouse.state.current == 1){
					colour = drawColour;
				}else if(mouse.state.current == 3){
					colour = eraseColour;
				}else{
					return;
				}

				if(mouse.state.last != mouse.state.current){
					state.capture();
					applyBrush(mouse.gridPosition.x, mouse.gridPosition.y, colour);
					return;
				}

				if(mouse.lastPosition.x == null || mouse.lastPosition.y == null || mouse.gridPosition.x == null || mouse.gridPosition.y == null){
					return;
				}
				line(mouse.lastPosition.x, mouse.lastPosition.y, mouse.gridPosition.x, mouse.gridPosition.y, colour);
			}

			function tool_floodfill(){
				var colour;
				if(mouse.state.current == 1){
					colour = drawColour;
				}else if(mouse.state.current == 3){
					colour = eraseColour;
				}else{
					return;
				}
				if(typeof colour == 'object' && colour != null){				
					applyFloodfill(mouse.gridPosition.x, mouse.gridPosition.y, rgbaToString(colour.rgba));
				}else{
					applyFloodfill(mouse.gridPosition.x, mouse.gridPosition.y, colour);
				}
			}

			function getPixel(x, y, resultType){
				rval = false;
				if(resultType == undefined){
					resultType = 'string'
				}else{
					if({'string' : 1, 'object' : 1}[resultType] != 1){
						throw "getPixel resultType argument must either 'string' or 'object'";
					}
				}
				if(x >= 0 && y >= 0 && x < gridInfo.w && y < gridInfo.h){
					rval = frames[activeFrame].cells[x][y].colour;
				}

				if(resultType == 'string' && rval != null){
					rval = rgbaToString(rval);
				}
				return rval;
			}

			function setHighlight(x, y, colour){
				if(x >= 0 && y >= 0 && x < gridInfo.w && y < gridInfo.h){
					if(colour == undefined) colour = null;
					
					frames[activeFrame].cells[x][y].setHighlight(colour);
					frames[activeFrame].cells[x][y].refresh();
				}
			}

			function applyBrush(px, py, colour){
				if(currentBrush == null){
					putPixel(x, y, colour);
					return;
				}

				var x, y;
				var w = brushes[currentBrush].width;
				var h = brushes[currentBrush].height;
				var xOffset = w >> 1;
				var yOffset = h >> 1;
				for(x = 0; x < w; x++){
					for(y = 0; y < w; y++){
						if(brushes[currentBrush].val(x, y)){
							putPixel(px + x - xOffset, py + y - yOffset, colour);
						}
					}
				}
			}

			function putPixel(x, y, colour){
				var symx, symy;
				if(x >= 0 && y >= 0 && x < gridInfo.w && y < gridInfo.h){
					if(colour == undefined) colour = null;
					
					frames[activeFrame].cells[x][y].setColour(colour);
					frames[activeFrame].cells[x][y].refresh();
					switch(symmetry){
						case 'vertical':
							symx = gridInfo.w - 1 - x;
							symy = y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case 'horizontal':
							symx = x;
							symy = gridInfo.h - 1 - y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case 'diagonal1':
							symx = y;
							symy = x;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case 'diagonal2':
							symx = gridInfo.w - 1 - y;
							symy = gridInfo.w - 1 - x;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case 'rotational2':
							symx = gridInfo.w - 1 - x;
							symy = gridInfo.h - 1 - y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case 'rotational4':
							symx = gridInfo.w - 1 - x;
							symy = gridInfo.h - 1 - y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = y;
							symy = gridInfo.h - 1 - x;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = gridInfo.w - 1 - y;
							symy = x;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;
						case '4way':
							symx = gridInfo.w - 1 - x;
							symy = y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = x;
							symy = gridInfo.h - 1 - y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = gridInfo.w - 1 - x;
							symy = gridInfo.h - 1 - y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}
							break;

						case '8way':
							symx = gridInfo.w - 1 - x;
							symy = y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = x;
							symy = gridInfo.h - 1 - y;
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = y;
							symy = x;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symx = gridInfo.w - 1 - x;
							symy = gridInfo.h - 1 - y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}


							symy = gridInfo.h - 1 - x;
							symx = y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symy = x;
							symx = gridInfo.w - 1 - y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}

							symy = gridInfo.h - 1 - x;
							symx = gridInfo.w - 1 - y;
							symx = symx < 0 ? 0 : (symx >= gridInfo.w - 1 ? gridInfo.w - 1 : symx);
							symy = symy < 0 ? 0 : (symy >= gridInfo.h - 1 ? gridInfo.h - 1 : symy);
							if(symx != x || symy != y){
								frames[activeFrame].cells[symx][symy].setColour(colour);
							}


							break;
					}
				}
			}

			function line(x1, y1, x2, y2, colour){
				var x, y, dx, dy, xsgn, ysgn, absdx, absdy;
				var tally = 0;

				x = x1;
				y = y1;
				dx = x2 - x1;
				dy = y2 - y1;
				xsgn = Math.sign(dx);
				ysgn = Math.sign(dy);
				absdx = Math.abs(dx);
				absdy = Math.abs(dy);

				applyBrush(x1, y1, colour);
				applyBrush(x2, y2, colour);
				if(Math.abs(dy) > Math.abs(dx)){
					for(y = y1; y != y2; y += ysgn){
						tally += absdx;
						if(tally > absdy){
							tally -= absdy;
							x += xsgn;
						}
						applyBrush(x, y, colour);
					}
				}else{
					for(x = x1; x != x2; x += xsgn){
						tally += absdy;
						if(tally > absdx){
							tally -= absdx;
							y += ysgn;
						}
						applyBrush(x, y, colour);
					}
				}
			}

			function applyFloodfill(x, y, colour, iteration){
				var minx = x, maxx = x, dx, targetColour;

				targetColour = getPixel(x, y);
				if(targetColour == colour) return;

				if(iteration == undefined){
					iteration = 1;
					state.capture();
				}
				if(iteration > 1000){
					debugger;
				}
				for(dx = 0; getPixel(dx + x, y) == targetColour; dx++){
					maxx = dx + x;
				}
				for(dx = -1; getPixel(dx + x, y) == targetColour; dx--){
					minx = dx + x;
				}


				for(x = minx; x <= maxx; x++){
					putPixel(x, y, colour);
				}

				for(x = minx; x <= maxx; x++){
					if(getPixel(x, y + 1) == targetColour){
						applyFloodfill(x, y + 1, colour, iteration + 1);
					}
					if(getPixel(x, y - 1) == targetColour){
						applyFloodfill(x, y - 1, colour, iteration + 1);
					}
				}

			}

			function toggleGrid(){
				gridInfo.showing = !gridInfo.showing;
				refreshGrid();
			}

			function refreshGrid(){
				if(gridInfo.showing){
					$('#editgrid').css({
						'border-width': '1px 0 0 1px'
					});
					$('.editcell').css({
						'border-style' : 'solid',
						'width' : gridInfo.cellWidth - 1,
						'height' : gridInfo.cellHeight - 1
					});

				}else{
					$('#editgrid').css({
						'border-width': '1px'
					});
					$('.editcell').css({
						'border-style' : 'none',
						'width' : gridInfo.cellWidth,
						'height' : gridInfo.cellHeight 
					});
				}
			}

			function downloadImage(){
				frames[activeFrame].download();
			}

			var transform = (function(){
				var _active = 0;
				return function(action){
					if(_active) return;
					_active = 1;
					state.capture();
					var x, y, width, height;
					var newData = [], doRebuild = 0;

					if({'rotright' : 1, 'rotleft' : 1, 'diagonal1':1, 'diagonal2' : 1}[action] == 1){
						width = gridInfo.h;
						height = gridInfo.w;
						doRebuild = 1;
					}else if({'vflip' : 1, 'hflip' : 1, 'shiftleft' : 1, 'shiftright' : 1, 'shiftup':1, 'shiftdown':1}[action] == 1){
						width = gridInfo.w;
						height = gridInfo.h;
					}else{
						_active = 0;
						return;
					}
					var readPixel = {
						'rotright': function(x, y){
								return getPixel(y, width - x - 1);
							},
						'rotleft': function(x, y){
								return getPixel(height - y - 1, x);
							},
						'diagonal1': function(x, y){
								return getPixel(y, x);
							},
						'diagonal2': function(x, y){
								return getPixel(height - y - 1, width - x - 1);
							},
						'hflip': function(x, y){
								return getPixel(width - x - 1, y);
							},
						'vflip': function(x, y){
								return getPixel(x, height - y - 1);
							},
						'shiftleft': function(x, y){
								return getPixel(x == width - 1 ? 0 : x + 1, y);
							},
						'shiftright': function(x, y){
								return getPixel(x == 0 ? width - 1 : x - 1, y);
							},
						'shiftup': function(x, y){
								return getPixel(x, y == height - 1 ? 0 : y + 1);
							},
						'shiftdown': function(x, y){
								return getPixel(x, y == 0 ? height - 1 : y - 1);
							}
					}[action];

					for(x = 0; x < width; x++){
						newData[x] = [];
						for(y = 0; y < height; y++){
							newData[x][y] = readPixel(x, y);
						}
					}

					gridInfo.w = width;
					gridInfo.h = height;

					if(doRebuild){
						frames[activeFrame].rebuild();
						frames[activeFrame].resetCanvas();
					}
					renderGrid();
					refreshGrid();
					for(x = 0; x < width; x++){
						for(y = 0; y < height; y++){
							frames[activeFrame].cells[x][y].setColour(newData[x][y]);
						}
					}
					_active = 0;
				};
			})();

			var applyMatrix = (function(){
				var _active = 0;

				return function(matrix){
					if(_active != 0) return;
					_active = 1;
					state.capture();
					var x, y, p, dx, dy, leftX, topY, width, height, idx, n, rgb;
					var newData = [], colourTally, matrixTally, newColour;
					var frame = frames[activeFrame];

					if(!(matrix.width % 2 && matrix.height % 2)){
						throw "applyMatrix requires matrices that have odd-numbered dimensions";
					}
					width = matrix.width;
					height = matrix.height;
					leftX = -Math.floor(matrix.width / 2);
					topY = -Math.floor(matrix.height / 2);
					var mainColour = getPixel(x, y).rgba;

					for(x = 0; x < gridInfo.w; x++){
						newData[x] = [];
						for(y = 0; y < gridInfo.h; y++){
							colourTally = {red : 0, green : 0, blue : 0, alpha : 0};
							for(dx = 0; dx < matrix.width; dx++){
								for(dy = 0; dy < matrix.height; dy++){
									rgb = frames[activeFrame].cells[(x + leftX + dx + gridInfo.w) % gridInfo.w][(y + topY + dy + gridInfo.h) % gridInfo.h].element.css('background-color');
									p = rgb.substring(rgb.indexOf('(') + 1, rgb.lastIndexOf(')')).split(/,\s*/);
									if(p.length == 3) p[p.length] = 1;
									colourTally.red += p[0] * matrix.val(dx, dy);
									colourTally.green += p[1] * matrix.val(dx, dy);
									colourTally.blue += p[2] * matrix.val(dx, dy);
									colourTally.alpha += p[3] * matrix.val(dx, dy);
								}
							}
							colourTally.alpha = Math.min(1, Math.max(0, parseFloat(colourTally.alpha).toFixed(3)));
							if(colourTally.alpha == 0){
								newData[x][y] = null;
							}else{
								colourTally.red = Math.min(255, Math.max(0, Math.round(colourTally.red)));
								colourTally.green = Math.min(255, Math.max(0, Math.round(colourTally.green)));
								colourTally.blue = Math.min(255, Math.max(0, Math.round(colourTally.blue)));
								newData[x][y] = rgbaToString(colourTally);
							}
						}
					}
					var ctx = frame.context;
					ctx.clearRect(0, 0, gridInfo.w, gridInfo.h);
					for(x = 0; x < gridInfo.w; x++){
						for(y = 0; y < gridInfo.h; y++){
							if(newData[x][y] == null){
								frame.cells[x][y].setColour(null);
							}else{
								frame.cells[x][y].setColour(newData[x][y]);
							}
						}
					}
					_active = 0;
				};
			})();

			function setSymmetry(sym){
				var valid = {'vertical' : 1, 'horizontal' : 1, 'diagonal1' : 1, 'diagonal2' : 1, 'rotational2' : 1, 'rotational4' : 1, '4way' : 1, '8way' : 1};
				if(valid[sym] == 1){
					symmetry = sym == symmetry ? null : sym;

					$('.activeSymmetry').removeClass('activeSymmetry');
					if(symmetry != null){
						$('#symmetry_' + sym).addClass('activeSymmetry');
					}
				}
			}


			function handleImport(e){
				var reader = new FileReader();
				reader.onload = function(event){
					var img = new Image();
					img.onload = function(){
						var canvas = $('#thumbnail')[0];
						canvas.width = img.width;
						canvas.height = img.height;
						var ctx = canvas.getContext('2d');
						ctx.drawImage(img,0,0);
						frames[activeFrame].refreshFromCanvas();
					}
					img.src = event.target.result;
				}
				reader.readAsDataURL(e.target.files[0]);
			}

			$(document).ready(function(){
				var x, y;
				frames[activeFrame] = new frameClass(gridInfo);
				frames[activeFrame].calcCellSize();

				//----------------- render the actual pixel grid
				renderGrid();

				//----------------- render default colours
				colours = [];
				colourClass.createColour(0, 0, 0, 1);
				colourClass.createColour(255, 255, 255, 1);
				/*
				for(var n = 0; n < 31; n++){
					colours[n + 3] = new colourClass({
						'red' : 128 + Math.floor(128 * Math.cos(n / 5)),
						'green' : 128 + Math.floor(128 * Math.cos((n + 2) / 5)),
						'blue' : 128 + Math.floor(128 * Math.cos((n +  5) / 5)),
						'alpha' : 31 / (n + 31)
					});
					colours[n + 3].addButton($('#palette'));
				}
				*/
				drawColour = colours[0];
				colours[0].element.addClass('selectedDrawColour');
				eraseColour = null;

				$('#palette').css('height', $('#editgrid').height() + 'px');

				//----------------- set up mouse events

				$(document).mouseup(function(evt){
					mouse.state.current = 0;
					handleMouseAction(evt);
					mouse.state.last = mouse.state.current;
				});
				$('#editgrid').mousedown(function(evt){
					mouse.state.current = evt.which;
					handleMouseAction(evt);
					mouse.state.last = mouse.state.current;
					return false;
				});

				$('#editgrid').contextmenu(function(evt){
					mouse.state.current = 3;
					handleMouseAction(evt);
					mouse.state.last = mouse.state.current;
					return false;
				});

				$('#editgrid').mousemove(function(evt){
					if(mouse.state.current) handleMouseAction(evt);
					mouse.state.last = mouse.state.current;
					return false;
				});

				//------------------------------- file input
				var imageLoader = document.getElementById('imageLoader');
				imageLoader.addEventListener('change', handleImport, false);
				var canvas = document.getElementById('thumbnail');
				var ctx = canvas.getContext('2d');

				for(var n in brushes){
					
					$('#brushWrapper').append(renderBrushButton(n));
				}


			});

		</script>
	</head>
	<body>
		<div id="appWrapper">
			<div id="topToolBar" class="toolbar">
				<a title="Undo" onclick="state.undo(); return false;" href="#"><img src="images/undo.png"/></a>
				<a title="Redo" onclick="state.redo(); return false;" href="#"><img src="images/redo.png"/></a>
				<a title="Toggle Grid" onclick="toggleGrid(); return false;" href="#"><img src="images/grid.png"/></a>
				<a title="Clear" onclick="frames[activeFrame].clear(); return false;" href="#"><img src="images/clear.png"/></a>

				<div id="symmetryButtons">
					<a id="symmetry_vertical" title="Vertical Symmetry" onclick="setSymmetry('vertical'); return false;" href="#"><img src="images/symmetry_vertical.png"/></a>
					<a id="symmetry_horizontal" title="Horizontal Symmetry" onclick="setSymmetry('horizontal'); return false;" href="#"><img src="images/symmetry_horizontal.png"/></a>
					<a id="symmetry_diagonal1" title="Diagonal Symmetry 1" onclick="setSymmetry('diagonal1'); return false;" href="#"><img src="images/symmetry_diagonal1.png"/></a>
					<a id="symmetry_diagonal2" title="Diagonal Symmetry 2" onclick="setSymmetry('diagonal2'); return false;" href="#"><img src="images/symmetry_diagonal2.png"/></a>
					<a id="symmetry_4way" title="4 Way Symmetry" onclick="setSymmetry('4way'); return false;" href="#"><img src="images/symmetry_4Way.png"/></a>
					<a id="symmetry_8way" title="8 Way Symmetry" onclick="setSymmetry('8way'); return false;" href="#"><img src="images/symmetry_8way.png"/></a>
					<a id="symmetry_rotational2" title="Rotational Symmetry" onclick="setSymmetry('rotational2'); return false;" href="#"><img src="images/symmetry_rotational2.png"/></a>
					<a id="symmetry_rotational4" title="Rotational Symmetry (both axis)" onclick="setSymmetry('rotational4'); return false;" href="#"><img src="images/symmetry_rotational4.png"/></a>
				</div>

			</div>
				<div id="leftToolBar" class="toolbar">
					<div id="canvasHolder"></div>
					<div class="sidebarSeparator"></div>
					<div>
						<a title="Pencil" id="tool_pencil" onclick="setTool('pencil'); return false;" href="#" class="currentTool"><img src="images/pencil.png"/></a>
						<a title="Paint" id="tool_paintbrush" onclick="setTool('paintbrush'); return false;" href="#"><img src="images/paint.png"/></a>
						<a title="Fill" id="tool_floodfill" onclick="setTool('floodfill'); return false;" href="#"><img src="images/bucketfill.png"/></a>
						<a title="Sample" id="tool_sample" onclick="setTool('sample'); return false;" href="#"><img src="images/sample.png"/></a>

						<div class="sidebarSeparator"></div>

						<div id="brushWrapper"></div>

						<div class="sidebarSeparator"></div>

						<a title="Blur" onclick="applyMatrix(new matrixClass([[0.05, 0.05, 0.05], [0.05, 0.6, 0.05], [0.05, 0.05, 0.05]])); return false;" href="#"><img src="images/blur.png"/></a>
						<a title="Heavy Blur" onclick="applyMatrix(new matrixClass([[0.1, 0.1, 0.1], [0.1, 0.2, 0.1], [0.1, 0.1, 0.1]])); return false;" href="#"><img src="images/HeavyBlur.png"/></a>
						<a title="Fade" onclick="applyMatrix(new matrixClass([[.8]])); return false;" href="#"><img src="images/fade.png"/></a>
						<a title="Intensify" onclick="applyMatrix(new matrixClass([[1.2]])); return false;" href="#"><img src="images/intensify.png"/></a>
						<a title="Vertical Blur" onclick="applyMatrix(new matrixClass([[0, 0.33, 0],[0, 0.34, 0],[0, 0.33, 0]])); return false;" href="#"><img src="images/verticalblur.png"/></a>
						<a title="Horizontal Blur" onclick="applyMatrix(new matrixClass([[0, 0, 0],[0.33, 0.34, 0.33],[0, 0, 0]])); return false;" href="#"><img src="images/horizontalblur.png"/></a>
						<a title="Edge Ripples" onclick="applyMatrix(new matrixClass([[0.2, 0.2, 0.2], [0.2, -0.6, 0.2], [0.2, 0.2, 0.2]])); return false;" href="#"><img src="images/ripples.png"/></a>

						<div class="sidebarSeparator"></div>

						<a title="Move Left" onclick="transform('shiftleft'); return false;" href="#"><img src="images/left.png"/></a>
						<a title="Move Up" onclick="transform('shiftup'); return false;" href="#"><img src="images/up.png"/></a>
						<a title="Move Right" onclick="transform('shiftright'); return false;" href="#"><img src="images/right.png"/></a>
						<a title="Rotate Counter-Clockwise" onclick="transform('rotleft'); return false;" href="#"><img src="images/rotateleft.png"/></a>
						<a title="Move Down" onclick="transform('shiftdown'); return false;" href="#"><img src="images/down.png"/></a>
						<a title="Rotate Clockwise" onclick="transform('rotright'); return false;" href="#"><img src="images/rotateright.png"/></a>
						<a title="Flip Vertially" onclick="transform('vflip'); return false;" href="#"><img src="images/vflip.png"/></a>
						<a title="Flip Horizontally" onclick="transform('hflip'); return false;" href="#"><img src="images/hflip.png"/></a>
						<a title="Flip Diagonally" onclick="transform('diagonal1'); return false;" href="#"><img src="images/dflip1.png"/></a>
						<a title="Flip Diagon Alley" onclick="transform('diagonal2'); return false;" href="#"><img src="images/dflip2.png"/></a>
					</div>
				</div>
				<div id="editgrid"></div>
				<div id="paletteWrapper">
					<div id="palette">
						<div id="paletteTopSpacing"></div>
					</div>
					<div id="colourToolBoxWrapper">
						<div id="colourToolBox" class="colourToolBox button">
							<img src="images/palette.png" class="paletteButton" onclick="colourClass.createColour(255, 255, 255, 1)">
							<img src="images/gradient.png" class="paletteButton" onclick="alert('not yet implemented');">
						</div>
					</div>
				</div>
				<br/>
				<div id="footer">
					<a class="uiButton" href="#" onclick="$('#imageLoader').trigger('click'); return false;">Open</a>
					<input type="file" id="imageLoader" name="imageLoader" id="imageLoader" style="display:inline-block; width:0px; height:0px"/>

					<a onclick="downloadImage(); return false;" href="#" class="uiButton">Save As</a>
					<div id="filenameWrapper">
						<input type="text" id="filename"></input>.PNG
					</div>
				</div>



		</div>
		<br/><br/>
		<div style="text-align: left; font-size: 80%" id="todo">
			TODO:
			<ul>
				<li>custom gradient generating</li>
				<li>airbrush, circle and rectangle tools</li>
				<li>frames</li>
				<li>add mouse-controlled matrix application (e.g. "smudge")</li>
				<li>add keyboard shortcuts</li>
				<li>copy/paste functionality</li>
			</ul>
			Maybe:
			<ul>
				<li>abillity to create custom matrices</li>
				<li>allow applying translucent colours on top of others, blending them and generating new colours on the fly</li>
				<li>allow selection of underlay frame (additional tab set?)</li>
			</ul>
			Bugs:
			<ul>
				<li>Undo/redo is flakey in certain cases.</li>
			</ul>
		</div>

	</body>
</html>
