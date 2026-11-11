/* Original photo renderer. Coordinates are normalized to the source photo. */
(() => {
  'use strict';
  function inversePlane(corners) {
    if (!Array.isArray(corners) || corners.length !== 4 || corners.some(p => !Array.isArray(p) || p.length !== 2 || p.some(v => !Number.isFinite(v) || v < 0 || v > 1))) throw Error('Use four corners within the photo.');
    const cross = corners.map((a,i) => {const b=corners[(i+1)%4],c=corners[(i+2)%4];return (b[0]-a[0])*(c[1]-b[1])-(b[1]-a[1])*(c[0]-b[0]);});
    if (!cross.every(x=>x>0.000001) && !cross.every(x=>x< -0.000001)) throw Error('Corners must form a convex shape without crossed edges.');
    const target=[[0,0],[1,0],[1,1],[0,1]], rows=[];
    corners.forEach(([x,y],i)=>{const [u,v]=target[i];rows.push([x,y,1,0,0,0,-u*x,-u*y,u],[0,0,0,x,y,1,-v*x,-v*y,v]);});
    for(let i=0;i<8;i++){
      let pivot=i;for(let j=i+1;j<8;j++)if(Math.abs(rows[j][i])>Math.abs(rows[pivot][i]))pivot=j;
      [rows[i],rows[pivot]]=[rows[pivot],rows[i]];
      const divisor=rows[i][i];if(Math.abs(divisor)<1e-10)throw Error('Surface is too narrow.');
      for(let k=i;k<9;k++)rows[i][k]/=divisor;
      for(let j=0;j<8;j++)if(j!==i){const factor=rows[j][i];for(let k=i;k<9;k++)rows[j][k]-=factor*rows[i][k];}
    }
    const h=rows.map(r=>r[8]);return new Float32Array([h[0],h[3],h[6],h[1],h[4],h[7],h[2],h[5],1]);
  }
  function loadImage(src) {
    return new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve(img);img.onerror=()=>reject(Error('Could not load the photo or texture.'));img.src=src;});
  }
  function canvasFor(width,height){const c=document.createElement('canvas');c.width=width;c.height=height;return c;}
  function polygonMask(width,height,corners){const c=canvasFor(width,height),ctx=c.getContext('2d');ctx.fillStyle='#000';ctx.fillRect(0,0,width,height);ctx.fillStyle='#fff';ctx.beginPath();corners.forEach(([x,y],i)=>i?ctx.lineTo(x*width,y*height):ctx.moveTo(x*width,y*height));ctx.closePath();ctx.fill();return c;}
  class PhotoRenderer {
    constructor(canvas){
      this.canvas=canvas;const gl=this.gl=canvas.getContext('webgl',{preserveDrawingBuffer:true,alpha:false});
      if(!gl)throw Error('This browser cannot render the preview. Try a browser with WebGL enabled.');
      const vertex=`attribute vec2 position; varying vec2 uv; void main(){gl_Position=vec4(position,0.,1.);uv=vec2((position.x+1.)*.5,(1.-position.y)*.5);}`;
      const fragment=`precision highp float;
        varying vec2 uv; uniform sampler2D photo; uniform sampler2D mask; uniform sampler2D stone;
        uniform mat3 projection; uniform vec2 repeats; uniform float angle; uniform float grout;
        uniform vec3 groutColor; uniform float shade; uniform bool base;
        void main(){vec3 original=texture2D(photo,uv).rgb;if(base){gl_FragColor=vec4(original,1.);return;}
          vec3 mapped=projection*vec3(uv,1.);if(abs(mapped.z)<0.00001)discard;
          vec2 p=mapped.xy/mapped.z;float co=cos(angle),si=sin(angle);
          p=mat2(co,-si,si,co)*(p-.5)+.5;vec2 tile=fract(p*repeats);
          vec3 color=texture2D(stone,tile).rgb;
          float edge=min(min(tile.x,1.-tile.x),min(tile.y,1.-tile.y));
          if(edge<grout)color=groutColor;
          float light=clamp(dot(original,vec3(.2126,.7152,.0722))/.65,.45,1.35);
          color*=mix(1.,light,shade);gl_FragColor=vec4(color,texture2D(mask,uv).r);
        }`;
      const compile=(type,source)=>{const s=gl.createShader(type);gl.shaderSource(s,source);gl.compileShader(s);if(!gl.getShaderParameter(s,gl.COMPILE_STATUS))throw Error(gl.getShaderInfoLog(s));return s;};
      const vs=compile(gl.VERTEX_SHADER,vertex),fs=compile(gl.FRAGMENT_SHADER,fragment),program=this.program=gl.createProgram();gl.attachShader(program,vs);gl.attachShader(program,fs);gl.linkProgram(program);gl.deleteShader(vs);gl.deleteShader(fs);if(!gl.getProgramParameter(program,gl.LINK_STATUS))throw Error('Could not initialize the preview renderer.');
      gl.useProgram(program);this.buffer=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,this.buffer);gl.bufferData(gl.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1]),gl.STATIC_DRAW);
      const pos=gl.getAttribLocation(program,'position');gl.enableVertexAttribArray(pos);gl.vertexAttribPointer(pos,2,gl.FLOAT,false,0,0);
      this.locations={};['photo','mask','stone','projection','repeats','angle','grout','groutColor','shade','base'].forEach(n=>this.locations[n]=gl.getUniformLocation(program,n));
      this.photo=null;this.surfaces=[];this.original=false;
    }
    texture(image){const gl=this.gl,t=gl.createTexture();gl.bindTexture(gl.TEXTURE_2D,t);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.CLAMP_TO_EDGE);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.CLAMP_TO_EDGE);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.LINEAR);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,image);return t;}
    setPhoto(image){if(this.photo)this.gl.deleteTexture(this.photo);this.canvas.width=image.width;this.canvas.height=image.height;this.photo=this.texture(image);}
    clearSurfaces(){this.surfaces.forEach(s=>{this.gl.deleteTexture(s.maskTexture);this.gl.deleteTexture(s.stoneTexture);});this.surfaces=[];}
    addSurface(state,mask,image){const surface={state,mask,maskTexture:this.texture(mask),stoneTexture:this.texture(image)};this.surfaces.push(surface);return surface;}
    updateMask(surface){const gl=this.gl;gl.bindTexture(gl.TEXTURE_2D,surface.maskTexture);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,surface.mask);}
    setStone(surface,image){this.gl.deleteTexture(surface.stoneTexture);surface.stoneTexture=this.texture(image);}
    render(){
      const gl=this.gl,L=this.locations;if(!this.photo)return;gl.viewport(0,0,this.canvas.width,this.canvas.height);gl.useProgram(this.program);
      const bind=(unit,t,n)=>{gl.activeTexture(gl.TEXTURE0+unit);gl.bindTexture(gl.TEXTURE_2D,t);gl.uniform1i(L[n],unit);};
      bind(0,this.photo,'photo');bind(1,this.photo,'mask');bind(2,this.photo,'stone');gl.disable(gl.BLEND);gl.uniform1i(L.base,1);gl.drawArrays(gl.TRIANGLE_STRIP,0,4);
      if(this.original)return;gl.enable(gl.BLEND);gl.blendFunc(gl.SRC_ALPHA,gl.ONE_MINUS_SRC_ALPHA);gl.uniform1i(L.base,0);
      for(const s of this.surfaces){if(!s.state.enabled)continue;const st=s.state;bind(1,s.maskTexture,'mask');bind(2,s.stoneTexture,'stone');gl.uniformMatrix3fv(L.projection,false,inversePlane(st.corners));gl.uniform2f(L.repeats,st.repeat,st.repeat);gl.uniform1f(L.angle,st.rotation*Math.PI/180);gl.uniform1f(L.grout,st.grout);gl.uniform3fv(L.groutColor,[.78,.76,.72]);gl.uniform1f(L.shade,st.shade);gl.drawArrays(gl.TRIANGLE_STRIP,0,4);}
    }
    dispose(){this.clearSurfaces();if(this.photo)this.gl.deleteTexture(this.photo);this.gl.deleteBuffer(this.buffer);this.gl.deleteProgram(this.program);}
  }
  window.RoomPhoto={PhotoRenderer,loadImage,canvasFor,polygonMask,inversePlane};
})();
